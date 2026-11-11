<?php
/**
 * بارگذاری لوکال فونت وزیرمتن (FD) از پوشهٔ fonts/ — بدون هیچ CDN خارجی.
 * همهٔ وزن‌ها (Thin تا Black) به صورت @font-face تعریف می‌شود.
 */

function vazir_font_css(string $prefix = 'fonts/'): string {
    // نگاشت پسوند فایل فونت به وزن استاندارد
    $weights = [
        'Thin'      => 100,
        'ExtraLight'=> 200,
        'Light'     => 300,
        'Regular'   => 400,
        'Medium'    => 500,
        'SemiBold'  => 600,
        'Bold'      => 700,
        'ExtraBold' => 800,
        'Black'     => 900,
    ];
    $out = '';
    foreach ($weights as $name => $w) {
        $file = $prefix . 'Vazirmatn-FD-' . $name . '.ttf';
        $out .= "@font-face{font-family:'Vazirmatn FD';font-style:normal;font-weight:{$w};font-display:swap;"
              . "src:url('" . $file . "') format('truetype');}\n";
    }
    // وزن بی‌نام (Regular) به عنوان پیش‌فرض خانوادهٔ «Vazirmatn»قدیمی هم پوشش می‌شود
    $out .= "@font-face{font-family:'Vazirmatn';font-style:normal;font-weight:400;font-display:swap;"
          . "src:url('" . $prefix . "Vazirmatn-FD-Regular.ttf') format('truetype');}";
    return $out;
}

/* نام خانوادهٔ فونت به صورت ثابت — همه‌جا یکسان استفاده می‌شود */
function font_stack(): string {
    return "'Vazirmatn FD',Vazirmatn,'IRANSans',Tahoma,system-ui,-apple-system,'Segoe UI',sans-serif";
}
