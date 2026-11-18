<?php
/*
 * PHP QR Code encoder
 *
 * Config file, feel free to modify
 */
     
    // ปรับ: เก็บ cache ที่ storage/qrcache (โฟลเดอร์ที่เว็บเซิร์ฟเวอร์เขียนได้ — IIS/Apache) แทน libs/phpqrcode/cache
    //       เขียนไม่ได้ = ไม่ใช้ cache (ช้าลงเล็กน้อยแต่ QR ยังสร้างได้) · ไม่เขียน log ของไลบรารี (ข้อผิดพลาดไปที่ log ของ PHP)
    $__qrCacheDir = dirname(__FILE__, 3).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'qrcache'.DIRECTORY_SEPARATOR;
    if (!is_dir($__qrCacheDir)) {
        @mkdir($__qrCacheDir, 0750, true);
    }
    define('QR_CACHEABLE', is_dir($__qrCacheDir) && is_writable($__qrCacheDir));               // use cache - more disk reads but less CPU power, masks and format templates are stored there
    define('QR_CACHE_DIR', $__qrCacheDir);                                                      // used when QR_CACHEABLE === true
    define('QR_LOG_DIR', false);                                                                // default error logs dir
    unset($__qrCacheDir);
    
    define('QR_FIND_BEST_MASK', true);                                                          // if true, estimates best mask (spec. default, but extremally slow; set to false to significant performance boost but (propably) worst quality code
    define('QR_FIND_FROM_RANDOM', false);                                                       // if false, checks all masks available, otherwise value tells count of masks need to be checked, mask id are got randomly
    define('QR_DEFAULT_MASK', 2);                                                               // when QR_FIND_BEST_MASK === false
                                                  
    define('QR_PNG_MAXIMUM_SIZE',  1024);                                                       // maximum allowed png image width (in pixels), tune to make sure GD and PHP can handle such big images
                                                  