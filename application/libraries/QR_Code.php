<?php

if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}
require_once APPPATH . "third_party/omnipay/vendor/autoload.php";
// require_once APPPATH . "third_party/omnipay/vendor/vendor/autoload.php";

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class QR_Code
{

    public function __construct()
    {
        $CI = &get_instance();
    }

    public function generate($upload_path,$text,$file_name)
    {
        $data = $text;
        $options = new QROptions([
            'version' => 5,
            'eccLevel' => QRCode::ECC_H,
            'scale' => 2,
            'imageBase64' => false,
            'imageTransparent' => true,
            'foregroundColor' => '#000000',
            'backgroundColor' => '#ffffff',
            'qrCodeHeight'    => 10,
            'qrCodeWidth'     => 10,
            'outputType' => QRCode::OUTPUT_IMAGE_PNG
        ]);

        // Instantiating the code QR code class
        $qrCode = new QRCode($options);

        // generating the QR code image happens here.
        // QRGdImage::dump() installs an error handler and does not remove it when it throws; left in place it
        // turns CodeIgniter's own error page into a second fatal. A missing QR image must not break the page.
        $before = self::current_error_handler();
        try {
            $qrCode->render($data, $upload_path . $file_name . '.png');
            return true;
        } catch (Throwable $e) {
            log_message('error', 'QR code generation failed for ' . $upload_path . $file_name . '.png: ' . $e->getMessage());
            return false;
        } finally {
            // Drop any handler the library left behind, back to the one active before the call.
            for ($i = 0; $i < 5 && self::current_error_handler() !== $before; $i++) {
                restore_error_handler();
            }
        }
    }

    private static function current_error_handler()
    {
        $h = set_error_handler(function () { return false; });
        restore_error_handler();
        return $h;
    }
}
