<?php

declare(strict_types=1);

namespace Modules\User\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthFlowException extends Exception
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $status,
        private readonly array $data = [],
        private readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    public static function invalidCredentials(): self
    {
        return new self(
            errorCode: 'INVALID_CREDENTIALS',
            message: "\u{0631}\u{0642}\u{0645}\u{0020}\u{0627}\u{0644}\u{0647}\u{0627}\u{062A}\u{0641}\u{0020}\u{0623}\u{0648}\u{0020}\u{0643}\u{0644}\u{0645}\u{0629}\u{0020}\u{0627}\u{0644}\u{0645}\u{0631}\u{0648}\u{0631}\u{0020}\u{063A}\u{064A}\u{0631}\u{0020}\u{0635}\u{062D}\u{064A}\u{062D}\u{0629}\u{002E}",
            status: Response::HTTP_UNAUTHORIZED,
        );
    }

    public static function accountNotActive(): self
    {
        return new self(
            errorCode: 'ACCOUNT_NOT_ACTIVE',
            message: "\u{0627}\u{0644}\u{062D}\u{0633}\u{0627}\u{0628}\u{0020}\u{063A}\u{064A}\u{0631}\u{0020}\u{0645}\u{0641}\u{0639}\u{0644}\u{0020}\u{062D}\u{0627}\u{0644}\u{064A}\u{0627}\u{064B}\u{002E}\u{0020}\u{064A}\u{0631}\u{062C}\u{0649}\u{0020}\u{0627}\u{0644}\u{062A}\u{0648}\u{0627}\u{0635}\u{0644}\u{0020}\u{0645}\u{0639}\u{0020}\u{0627}\u{0644}\u{062F}\u{0639}\u{0645}\u{002E}",
            status: Response::HTTP_FORBIDDEN,
        );
    }

    /** @param array<string, mixed> $extraData */
    public static function phoneVerificationRequired(string $phone, array $extraData = [], int $status = Response::HTTP_FORBIDDEN): self
    {
        return new self(
            errorCode: 'PHONE_VERIFICATION_REQUIRED',
            message: "\u{0631}\u{0642}\u{0645}\u{0020}\u{0627}\u{0644}\u{0647}\u{0627}\u{062A}\u{0641}\u{0020}\u{063A}\u{064A}\u{0631}\u{0020}\u{0645}\u{0624}\u{0643}\u{062F}\u{002E}\u{0020}\u{064A}\u{0631}\u{062C}\u{0649}\u{0020}\u{062A}\u{0623}\u{0643}\u{064A}\u{062F}\u{0020}\u{0631}\u{0642}\u{0645}\u{0020}\u{0627}\u{0644}\u{0647}\u{0627}\u{062A}\u{0641}\u{0020}\u{0644}\u{0644}\u{0645}\u{062A}\u{0627}\u{0628}\u{0639}\u{0629}\u{002E}",
            status: $status,
            data: array_merge([
                'phone' => $phone,
                'next_action' => 'verify_phone',
                'can_resend_otp' => true,
            ], $extraData),
        );
    }

    public static function userAlreadyRegistered(string $phone): self
    {
        return new self(
            errorCode: 'USER_ALREADY_REGISTERED',
            message: "\u{0644}\u{062F}\u{064A}\u{0643}\u{0020}\u{062D}\u{0633}\u{0627}\u{0628}\u{0020}\u{0645}\u{0633}\u{062C}\u{0644}\u{0020}\u{0645}\u{0633}\u{0628}\u{0642}\u{0627}\u{064B}\u{002E}\u{0020}\u{064A}\u{0631}\u{062C}\u{0649}\u{0020}\u{062A}\u{0633}\u{062C}\u{064A}\u{0644}\u{0020}\u{0627}\u{0644}\u{062F}\u{062E}\u{0648}\u{0644}\u{0020}\u{0628}\u{0627}\u{0633}\u{062A}\u{062E}\u{062F}\u{0627}\u{0645}\u{0020}\u{0631}\u{0642}\u{0645}\u{0020}\u{0627}\u{0644}\u{0647}\u{0627}\u{062A}\u{0641}\u{002E}",
            status: Response::HTTP_CONFLICT,
            data: [
                'phone' => $phone,
                'next_action' => 'login',
            ],
        );
    }

    public static function otpInvalid(): self
    {
        $message = "\u{0631}\u{0645}\u{0632}\u{0020}\u{0627}\u{0644}\u{062A}\u{062D}\u{0642}\u{0642}\u{0020}\u{063A}\u{064A}\u{0631}\u{0020}\u{0635}\u{062D}\u{064A}\u{062D}\u{002E}\u{0020}\u{064A}\u{0631}\u{062C}\u{0649}\u{0020}\u{0627}\u{0644}\u{0645}\u{062D}\u{0627}\u{0648}\u{0644}\u{0629}\u{0020}\u{0645}\u{0631}\u{0629}\u{0020}\u{0623}\u{062E}\u{0631}\u{0649}\u{002E}";

        return new self(
            errorCode: 'OTP_INVALID',
            message: $message,
            status: Response::HTTP_UNPROCESSABLE_ENTITY,
            errors: [
                'otp' => [$message],
            ],
        );
    }

    public static function otpExpired(): self
    {
        $message = "\u{0627}\u{0646}\u{062A}\u{0647}\u{062A}\u{0020}\u{0635}\u{0644}\u{0627}\u{062D}\u{064A}\u{0629}\u{0020}\u{0631}\u{0645}\u{0632}\u{0020}\u{0627}\u{0644}\u{062A}\u{062D}\u{0642}\u{0642}\u{002E}\u{0020}\u{064A}\u{0631}\u{062C}\u{0649}\u{0020}\u{0637}\u{0644}\u{0628}\u{0020}\u{0631}\u{0645}\u{0632}\u{0020}\u{062C}\u{062F}\u{064A}\u{062F}\u{002E}";

        return new self(
            errorCode: 'OTP_EXPIRED',
            message: $message,
            status: Response::HTTP_GONE,
            errors: [
                'otp' => [$message],
            ],
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function render(Request $request): JsonResponse
    {
        $payload = [
            'success' => false,
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
        ];

        if ($this->data !== []) {
            $payload['data'] = $this->data;
        }

        if ($this->errors !== []) {
            $payload['errors'] = $this->errors;
        }

        return response()->json($payload, $this->status);
    }
}
