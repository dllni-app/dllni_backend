<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Services\GeminiProductService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class RestaurantV2VoiceSearchController
{
    public function __invoke(Request $request, GeminiProductService $speech): JsonResponse
    {
        $request->validate(['audio' => ['required','file','mimes:wav','max:2048']]);
        $file = $request->file('audio');
        $stream = fopen($file->getRealPath(), 'rb');
        try {
            $header = fread($stream, 12);
            $valid = strlen($header) === 12 && substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WAVE';
            $rate = 0;
            $size = 0;
            while ($valid && !feof($stream)) {
                $chunk = fread($stream, 8);
                if (strlen($chunk) !== 8) break;
                $length = unpack('V', substr($chunk, 4))[1];
                if ($length > $file->getSize() || ftell($stream) + $length > $file->getSize()) { $valid = false; break; }
                if (substr($chunk, 0, 4) === 'fmt ' && $length >= 16) {
                    $fmt = fread($stream, 16);
                    $fields = unpack('vformat/vchannels/VsampleRate/VbyteRate/vblockAlign/vbits', $fmt);
                    $rate = $fields['byteRate'];
                    $valid = $fields['format'] === 1 && $fields['channels'] === 1 && $fields['bits'] === 16 && $fields['sampleRate'] === 16000 && $rate === 32000;
                    fseek($stream, $length - 16 + ($length % 2), SEEK_CUR);
                } else {
                    if (substr($chunk, 0, 4) === 'data') $size = $length;
                    fseek($stream, $length + ($length % 2), SEEK_CUR);
                }
            }
        } finally {
            fclose($stream);
        }
        if (!$valid || $rate === 0 || $size === 0 || $size / $rate > 31) {
            throw ValidationException::withMessages(['audio' => 'يرجى تسجيل صوت واضح لا يتجاوز 30 ثانية.']);
        }
        $transcript = $speech->transcribeRestaurantVoice($file);
        if ($transcript === null || mb_strlen($transcript) < 2) {
            return response()->json(['message'=>'تعذر فهم التسجيل، حاول مجدداً أو اكتب طلبك.'], 422);
        }

        return response()->json(['data'=>['transcript'=>mb_substr($transcript, 0, 500)]])->header('Cache-Control', 'no-store');
    }
}
