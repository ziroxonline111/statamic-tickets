<?php

namespace KareliaLabs\Tickets;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * QR codes as inline SVG. Needs no GD or Imagick, prints sharp at any size.
 */
final class Qr
{
    public static function svg(string $data): string
    {
        $options = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => false,
            'svgAddXmlHeader' => false,
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
            'quietzoneSize' => 2,
            'drawLightModules' => false,
            'connectPaths' => true,
            'cssClass' => 'kt-qr',
        ]);

        return (new QRCode($options))->render($data);
    }
}
