<?php
/**
 * ============================================================================
 *  سرویس وب و ای‌پی‌آی صفحه‌ساز فریا روان (PageBuilder API)
 *  ─ ذخیره بلادرنگ خروجی‌های بصری (HTML, CSS, JSON)
 *  ─ رندر بلادرنگ پیش‌نمایش ویجت‌ها در ویرایشگر
 *  ─ آپلود تصاویر برای کتابخانه دارایی‌های بصری (Asset Manager)
 * ============================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../core/PageBuilder.php';

header('Content-Type: application/json; charset=utf-8');

// فقط مدیران مجاز (superadmin و manager) می‌توانند تغییرات در صفحه‌ساز را ذخیره کنند
$admin = current_admin();
if (!$admin || !fr_admin_can($admin, 'pages')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'دسترسی غیرمجاز؛ لطفاً مجدداً وارد پنل مدیریت شوید.'], JSON_UNESCAPED_UNICODE);
    exit;
}

PageBuilder::ensureSchema();

$act = (string)post('act', (string)($_GET['act'] ?? ''));

// ۱. ذخیره اطلاعات برگه از صفحه‌ساز
if ($act === 'save') {
    csrf_guard();
    $pageId = (int)post('page_id');
    $html   = (string)post('content_html', '');
    $css    = (string)post('content_css', '');
    $json   = (string)post('builder_json', '');

    if ($pageId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'شناسه برگه نامعتبر است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $page = PageBuilder::getPage($pageId);
    if (!$page) {
        echo json_encode(['ok' => false, 'error' => 'برگه موردنظر یافت نشد.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $saved = PageBuilder::saveBuilderContent($pageId, $html, $css, $json ?: null);
    if ($saved) {
        if (function_exists('log_activity')) {
            log_activity('page_builder_save', 'page', $pageId, ['title' => $page['title']]);
        }
        echo json_encode(['ok' => true, 'message' => 'طراحی برگه با موفقیت ذخیره شد ✅'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['ok' => false, 'error' => 'خطا در ثبت اطلاعات در پایگاه داده.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ۲. رندر درجا و پیش‌نمایش ویجت
if ($act === 'render_widget') {
    $widget = trim((string)post('widget', (string)($_GET['widget'] ?? '')));
    $params = (array)($_POST['params'] ?? []);
    if ($widget === '') {
        echo json_encode(['ok' => false, 'error' => 'نام ویجت الزامی است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $rendered = PageBuilder::renderWidget($widget, $params, true);
    echo json_encode(['ok' => true, 'html' => $rendered], JSON_UNESCAPED_UNICODE);
    exit;
}

// ۳. آپلود رسانه برای کتابخانه Asset Manager در صفحه‌ساز
if ($act === 'upload_asset' || $act === 'upload') {
    csrf_guard();
    if (empty($_FILES['files'])) {
        echo json_encode(['ok' => false, 'error' => 'فایلی ارسال نشده است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $uploadedUrls = [];
    $files = $_FILES['files'];

    // پشتیبانی از تک فایل یا چندین فایل
    $fileList = [];
    if (is_array($files['name'])) {
        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $fileList[] = [
                    'name'     => $files['name'][$i],
                    'type'     => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error'    => $files['error'][$i],
                    'size'     => $files['size'][$i],
                ];
            }
        }
    } else {
        if ($files['error'] === UPLOAD_ERR_OK) {
            $fileList[] = $files;
        }
    }

    foreach ($fileList as $f) {
        $res = fr_handle_upload_categorized($f, 'general', ['jpg','jpeg','png','webp','svg','gif']);
        if ($res['ok'] && !empty($res['url'])) {
            $uploadedUrls[] = $res['url'];
        }
    }

    if (!empty($uploadedUrls)) {
        // ساختار منطبق با GrapesJS Asset Manager: { data: [ url1, url2 ] }
        echo json_encode(['ok' => true, 'data' => $uploadedUrls], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['ok' => false, 'error' => 'خطا در بارگذاری تصویر. فرمت یا حجم فایل مجاز نیست.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

echo json_encode(['ok' => false, 'error' => 'عملیات نامعتبر است.'], JSON_UNESCAPED_UNICODE);
