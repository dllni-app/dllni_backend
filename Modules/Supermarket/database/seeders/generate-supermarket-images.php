<?php

declare(strict_types=1);

/**
 * Original, unbranded illustrated demo packshots; no network or credentials.
 * Run: php Modules/Supermarket/database/seeders/generate-supermarket-images.php
 * The generated WebP files are checked in; Laravel does not need to render them.
 */
$catalog = json_decode(file_get_contents(__DIR__.'/supermarket-catalog.json'), true, 512, JSON_THROW_ON_ERROR);
$target = dirname(__DIR__, 4).'/public/seed-media/supermarket';
if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
    throw new RuntimeException('Cannot create artwork directory');
}
if (!extension_loaded('gd') || !function_exists('imagewebp')) {
    throw new RuntimeException('GD with WebP support is required to regenerate artwork.');
}
$font = collectFont(['C:/Windows/Fonts/arialbd.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf']);
$regular = collectFont(['C:/Windows/Fonts/arial.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf']);

function collectFont(array $paths): ?string {
    foreach ($paths as $path) {
        if (is_file($path)) return $path;
    }
    return null;
}
function rgb($im, string $hex, int $adjust = 0): int {
    $v = array_map(fn ($n) => max(0, min(255, hexdec($n) + $adjust)), str_split($hex, 2));
    return imagecolorallocate($im, ...$v);
}
function poly($im, array $points, int $color): void {
    imagefilledpolygon($im, $points, $color);
}
function roundRect($im, int $x, int $y, int $w, int $h, int $r, int $c): void {
    imagefilledrectangle($im, $x+$r, $y, $x+$w-$r, $y+$h, $c);
    imagefilledrectangle($im, $x, $y+$r, $x+$w, $y+$h-$r, $c);
    foreach ([[$x+$r,$y+$r],[$x+$w-$r,$y+$r],[$x+$r,$y+$h-$r],[$x+$w-$r,$y+$h-$r]] as [$a,$b]) {
        imagefilledellipse($im,$a,$b,$r*2,$r*2,$c);
    }
}
function textLine($im, string $text, int $cx, int $baseline, int $size, int $color, ?string $font, int $width = 260): void {
    if ($text === '') return;
    if ($font !== null) {
        do {
            $box=imagettfbbox($size,0,$font,$text);
            $w=$box[2]-$box[0];
            if ($w <= $width || $size <= 10) break;
            $size--;
        } while (true);
        imagettftext($im,$size,0,(int)($cx-$w/2),$baseline,$color,$font,$text);
    } else {
        imagestring($im,5,$cx-(int)(strlen($text)*4.5),$baseline-13,$text,$color);
    }
}
function label($im, array $a, int $x, int $y, int $w, int $h, ?string $font, ?string $regular): void {
    $accent=rgb($im,$a['color']); $ink=rgb($im,'24332f'); $paper=rgb($im,'fffdf7');
    roundRect($im,$x,$y,$w,$h,7,$paper);
    imagefilledrectangle($im,$x+8,$y+7,$x+$w-8,$y+11,$accent);
    textLine($im,'MARKET SELECT',$x+(int)($w/2),$y+28,10,$ink,$regular,$w-16);
    $words=explode(' ',$a['label']); $lines=[''];
    foreach ($words as $word) {
        $last=count($lines)-1;
        if (strlen($lines[$last].' '.$word)>17 && $lines[$last]!=='') $lines[]=$word;
        else $lines[$last]=trim($lines[$last].' '.$word);
    }
    $lines=array_slice($lines,0,3);
    $start=$y+45;
    foreach ($lines as $i=>$line) textLine($im,$line,$x+(int)($w/2),$start+$i*19,15,$ink,$font,$w-12);
    textLine($im,$a['size']??'',$x+(int)($w/2),$y+$h-11,11,$ink,$regular,$w-14);
}
function ellipse($im,int $x,int $y,int $w,int $h,string $hex): void {
    imagefilledellipse($im,$x,$y,$w,$h,rgb($im,$hex));
}
function fresh($im,array $a): void {
    $style=$a['style']; $hex=$a['color']; $main=rgb($im,$hex); $dark=rgb($im,$hex,-32); $light=rgb($im,$hex,28);
    $green=rgb($im,'537b43'); $stem=rgb($im,'745437'); $white=rgb($im,'fff5dd');
    if ($style==='banana') {
        foreach ([[204,250],[244,230],[284,214]] as [$x,$y]) {
            imagefilledellipse($im,$x,$y,185,135,$dark);
            imagefilledellipse($im,$x,$y-15,183,126,$main);
            imagefilledellipse($im,$x+4,$y-51,148,101,rgb($im,'f5f6f2'));
            imagefilledellipse($im,$x+76,$y-31,15,25,$stem);
        }
    } elseif ($style==='cucumber'||$style==='carrot') {
        foreach ([[163,296,290,141],[215,328,337,175],[116,253,242,104]] as [$x1,$y1,$x2,$y2]) {
            if($style==='carrot'){
                poly($im,[$x1,$y1,$x2-42,$y2+10,$x2+12,$y2+56],$main);
                poly($im,[$x2-42,$y2+10,$x2+12,$y2+56,$x2+23,$y2-6,$x2-10,$y2+15,$x2-20,$y2-24],$green);
                imageline($im,$x2-30,$y2+39,$x2-6,$y2+40,$dark);
            } else {
                imagesetthickness($im,60); imageline($im,$x1,$y1,$x2,$y2,$dark);
                imagefilledellipse($im,$x1,$y1,60,60,$dark); imagefilledellipse($im,$x2,$y2,60,60,$dark);
                imagesetthickness($im,39); imageline($im,$x1-4,$y1-4,$x2-4,$y2-4,$main);
                imagesetthickness($im,2);
                for($j=0;$j<5;$j++) imageline($im,$x1+15*$j-10,$y1-17*$j,$x1+15*$j-4,$y1-17*$j-5,$light);
            }
        }
    } elseif ($style==='grapes') {
        foreach ([[256,138],[219,177],[278,176],[315,180],[188,218],[249,223],[297,228],[211,267],[269,275],[243,310]] as [$x,$y]){
            imagefilledellipse($im,$x,$y,69,67,$dark);
            imagefilledellipse($im,$x-3,$y-6,63,62,$main);
            imagefilledellipse($im,$x-13,$y-17,18,12,$light);
        }
        poly($im,[255,145,241,91,260,78,269,137],$stem);
        imagefilledellipse($im,297,116,80,40,$green);
    } elseif ($style==='lettuce') {
        foreach ([[256,202,130,220],[187,244,105,160],[322,248,105,170],[231,267,117,166],[278,268,117,166]] as [$x,$y,$w,$h]) {
            imagefilledellipse($im,$x,$y,$w,$h,$dark);
            imagefilledellipse($im,$x-4,$y-7,$w-10,$h-9,$main);
            imageline($im,$x,$y+50,$x-6,$y-52,$light);
        }
    } else {
        foreach ([[177,260],[292,270],[253,167]] as $i=>[$x,$y]){
            $w=$style==='strawberry'?85:119; $h=$style==='potato'?94:114;
            imagefilledellipse($im,$x,$y,$w,$h,$dark);
            imagefilledellipse($im,$x-4,$y-5,$w-5,$h-6,$main);
            imagefilledellipse($im,$x-22,$y-27,29,20,$light);
            if(in_array($style,['apple','tomato','orange','pepper','strawberry','onion'],true)){
                imagesetthickness($im,5); imageline($im,$x,$y-45,$x+4,$y-65,$stem); imagesetthickness($im,1);
                poly($im,[$x-26,$y-48,$x,$y-57,$x+25,$y-49,$x+9,$y-40,$x,$y-27,$x-8,$y-43],$green);
            }
            if($style==='strawberry'||$style==='potato'||$style==='orange'){
                for($j=0;$j<9;$j++){
                    $dx=(($j%3)-1)*18; $dy=(intdiv($j,3)-1)*18;
                    imagefilledellipse($im,$x+$dx,$y+$dy,4,5,$style==='strawberry'?$white:$dark);
                }
            }
        }
    }
}
function drawArtwork(array $a, string $path, ?string $font, ?string $regular): void {
    $im=imagecreatetruecolor(512,512); imageantialias($im,true);
    $accent=rgb($im,$a['color']); $dark=rgb($im,$a['color'],-35); $light=rgb($im,$a['color'],30);
    $white=rgb($im,'f8f9f6'); $ink=rgb($im,'263632'); $silver=rgb($im,'c7d0cb');
    for($y=0;$y<512;$y++){
        $g=(int)(249-$y/38); imageline($im,0,$y,511,$y,imagecolorallocate($im,$g,min(255,$g+2),max(0,$g-1)));
    }
    // Soft studio floor shadow with nested low-contrast ellipses.
    for($i=0;$i<15;$i++) {
        $g=227-$i; imagefilledellipse($im,256,392,310-$i*8,48-$i*2,imagecolorallocate($im,$g,$g+2,$g));
    }
    $style=$a['style'];
    if(in_array($style,['tomato','cucumber','apple','orange','banana','grapes','carrot','pepper','potato','lettuce','onion','strawberry'],true)){
        fresh($im,$a);
        textLine($im,$a['label'],256,441,19,$ink,$font,400);
        textLine($im,$a['size']??'',256,467,13,$ink,$regular,350);
    } elseif($style==='bread'){
        foreach([[213,244],[281,250],[241,292]] as [$x,$y]){
            ellipse($im,$x,$y,198,106,'9b6438'); ellipse($im,$x-3,$y-8,193,99,$a['color']);
            for($i=0;$i<4;$i++){imagesetthickness($im,4); imageline($im,$x-55+$i*32,$y-21,$x-41+$i*32,$y+4,$light);}
            imagesetthickness($im,1);
        }
        label($im,$a,142,300,228,91,$font,$regular);
    } elseif($style==='eggs'){
        roundRect($im,116,235,280,122,18,$dark);
        foreach([0,1] as $row) for($i=0;$i<4;$i++){
            $x=151+$i*67;$y=210+$row*63;
            ellipse($im,$x,$y,54,79,'b9a48b');ellipse($im,$x-2,$y-3,51,75,'f2e6ce');
        }
        label($im,$a,139,287,234,87,$font,$regular);
    } elseif(in_array($style,['tray','meat','fish'],true)){
        roundRect($im,94,151,324,218,21,$dark);roundRect($im,102,158,309,201,16,$white);
        for($i=0;$i<3;$i++){
            if($style==='fish'){
                poly($im,[126+$i*64,185,193+$i*64,173,207+$i*64,256,146+$i*64,278],$accent);
                imagesetthickness($im,3);imageline($im,137+$i*64,205,193+$i*64,198,$light);imageline($im,142+$i*64,232,199+$i*64,224,$light);imagesetthickness($im,1);
            }else{ellipse($im,160+$i*95,225,102,112,$a['color']);imagefilledellipse($im,154+$i*95,211,64,49,$light);}
        }
        label($im,$a,131,266,250,103,$font,$regular);
    } elseif($style==='can'||$style==='tub'||$style==='jar'){
        $wide=$style==='tub'?254:($style==='jar'?176:198);
        $x=256-(int)($wide/2);$y=$style==='tub'?201:128;$bottom=371;
        imagefilledrectangle($im,$x,$y,512-$x,$bottom,$dark);
        for($i=0;$i<$wide;$i++){
            $delta=(int)(24*sin(pi()*$i/$wide))-12;imageline($im,$x+$i,$y,$x+$i,$bottom,rgb($im,$a['color'],$delta));
        }
        imagefilledellipse($im,256,$bottom,$wide,40,$dark);
        imagefilledellipse($im,256,$y,$wide,40,$silver);
        imagefilledellipse($im,256,$y-2,$wide-14,28,$style==='jar'?$accent:$white);
        if($style==='can'){imagefilledellipse($im,256,$y-1,29,11,$silver);imageellipse($im,256,$y-1,29,11,$dark);}
        label($im,$a,$x+13,$y+36,$wide-26,116,$font,$regular);
    } elseif(in_array($style,['bottle','spray','tube','multipack'],true)){
        $offsets=$style==='multipack'?[-110,0,110]:[0];
        foreach($offsets as $offset){
            $x=256+$offset;$w=$style==='multipack'?92:160;$l=$x-(int)($w/2);
            roundRect($im,$l,169,$w,205,25,$dark);
            roundRect($im,$l+5,166,$w-12,197,24,$accent);
            poly($im,[$l+5,179,$x-31,125,$x+31,125,$l+$w-7,179],$accent);
            roundRect($im,$x-32,100,64,32,5,$dark);
            if($style==='spray'){
                roundRect($im,$x-42,91,97,28,5,$dark);poly($im,[$x+18,115,$x+47,115,$x+29,153,$x+15,148],$dark);
            }
            roundRect($im,$l+14,187,12,125,6,$light);
            if($style!=='multipack')label($im,$a,$l+13,218,$w-26,121,$font,$regular);
            else roundRect($im,$l+10,231,$w-20,76,6,$white);
        }
        if($style==='multipack')label($im,$a,122,261,268,106,$font,$regular);
    } elseif($style==='carton'||$style==='box'||$style==='bar'||$style==='pouch'||$style==='roll'||$style==='bag'){
        $wide=in_array($style,['bar','pouch','roll'],true)?280:($style==='carton'?176:225);
        $height=in_array($style,['bar','pouch','roll'],true)?153:250;
        $x=256-(int)($wide/2);$y=375-$height;
        if($style==='bag'){
            poly($im,[$x+8,$y,$x+$wide-6,$y,$x+$wide+5,$y+20,$x+$wide-5,$y+$height-20,$x+$wide+6,$y+$height,$x-6,$y+$height,$x+5,$y+$height-20,$x-4,$y+20],$dark);
            poly($im,[$x+11,$y+14,$x+$wide-8,$y+14,$x+$wide-16,$y+$height-12,$x+14,$y+$height-12],$accent);
            imagefilledrectangle($im,$x+8,$y+6,$x+$wide-8,$y+15,$light);
            imagefilledrectangle($im,$x+8,$y+$height-14,$x+$wide-8,$y+$height-5,$dark);
        }else{
            poly($im,[$x,$y,$x+27,$y-20,$x+$wide+27,$y-20,$x+$wide,$y],$light);
            poly($im,[$x+$wide,$y,$x+$wide+27,$y-20,$x+$wide+27,$y+$height-16,$x+$wide,$y+$height],$dark);
            roundRect($im,$x,$y,$wide,$height,7,$accent);
        }
        if($style==='carton'){
            poly($im,[$x,$y,$x+38,$y-39,$x+$wide-35,$y-39,$x+$wide,$y],$light);
            roundRect($im,270,$y-46,45,14,3,$white);
        }
        label($im,$a,$x+15,$y+(int)(($height-120)/2),$wide-30,120,$font,$regular);
    } else {
        throw new RuntimeException('Unknown artwork shape: '.$style);
    }
    if(!imagewebp($im,$path,88))throw new RuntimeException('Failed to write '.$path);
    imagedestroy($im);
}
$all=array_merge($catalog['products'],array_values($catalog['legacyArtwork']));
foreach($all as $art){
    if(!preg_match('/^[a-z0-9-]+$/D',$art['key']))throw new RuntimeException('Invalid artwork key');
    drawArtwork($art,$target.'/'.$art['key'].'.webp',$font,$regular);
}
$stores = [
    'atrash' => ['AL ATRASH MARKET', '3e7864', ['rice', 'milk-new', 'tomato-new', 'chips-new']],
    'sultan' => ['AL SULTAN MARKET', '59813f', ['apple-new', 'carrot-new', 'eggs', 'olive-oil']],
    'noor' => ['AL NOOR MARKET', '527e9e', ['tuna-new', 'pasta', 'orange-juice', 'tissue']],
    'seller' => ['MARKET SELECT', 'a06c42', ['coffee', 'honey', 'laundry-new', 'rice-family']],
];
foreach ($stores as $key => [$title, $hex, $tiles]) {
    $cover = imagecreatetruecolor(1200, 600);
    imagefill($cover, 0, 0, rgb($cover, $hex));
    textLine($cover, $title, 600, 100, 37, rgb($cover, 'ffffff'), $font, 1100);
    textLine($cover, 'GROCERIES  /  FRESH FOOD  /  EVERYDAY ESSENTIALS', 600, 145, 16, rgb($cover, 'f4f6ee'), $regular, 1100);
    foreach ($tiles as $index => $tile) {
        $src = imagecreatefromwebp($target.'/'.$tile.'.webp');
        imagecopyresampled($cover, $src, 48 + $index * 280, 195, 0, 0, 264, 264, 512, 512);
        imagedestroy($src);
    }
    textLine($cover, 'YOUR EVERYDAY MARKET', 600, 528, 19, rgb($cover, 'ffffff'), $font);
    imagewebp($cover, $target.'/store-'.$key.'-cover.webp', 89);
    imagedestroy($cover);
    $logo = imagecreatetruecolor(512, 512);
    imagefill($logo, 0, 0, rgb($logo, $hex));
    $white = rgb($logo, 'ffffff');
    imagesetthickness($logo, 12);
    imageline($logo, 160, 176, 352, 176, $white);
    imageline($logo, 160, 176, 186, 296, $white);
    imageline($logo, 352, 176, 326, 296, $white);
    imageline($logo, 186, 296, 326, 296, $white);
    imagearc($logo, 256, 165, 110, 125, 180, 360, $white);
    for ($x = 220; $x <= 292; $x += 36) imageline($logo, $x, 201, $x, 272, $white);
    imagesetthickness($logo, 1);
    textLine($logo, $title, 256, 372, 25, $white, $font, 440);
    textLine($logo, 'FRESH EVERY DAY', 256, 408, 14, $white, $regular, 440);
    imagewebp($logo, $target.'/store-'.$key.'-logo.webp', 89);
    imagedestroy($logo);
}
echo 'Generated '.count($all)." original WebP product illustrations and 8 store branding assets.\n";
