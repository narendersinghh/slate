<?php
/** Import HTML/CSS/JS pages (core/Slate/SiteImport.php). */
use Slate\Auth;
use Slate\Router;
use Slate\Session;
use Slate\SiteImport;

return static function (Router $r): void {
    $guard = static function (): void {
        Auth::require('edit_posts');
        Auth::require('unfiltered_html'); // imported pages bring their own scripts
    };
    $r->get('/import', static function () use ($guard) {
        $guard();
        admin_view('import', ['step' => 'upload', 'title' => 'Import pages']);
    });
    $r->post('/import/upload', static function () use ($guard) {
        $guard();
        @set_time_limit(300);
        $files = [];
        $up = $_FILES['files'] ?? null;
        if (is_array($up) && is_array($up['name'] ?? null)) {
            foreach ($up['name'] as $i => $name) {
                if (($up['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($up['tmp_name'][$i])) {
                    $files[] = ['name' => (string)$name, 'tmp' => (string)$up['tmp_name'][$i]];
                }
            }
        }
        [$ok, $res] = SiteImport::receive($files);
        if (is_ajax()) {
            json_response($ok ? ['ok' => true, 'next' => admin_url('import/' . $res)] : ['ok' => false, 'error' => $res], $ok ? 200 : 422);
        }
        if (!$ok) {
            Session::flash('error', $res);
            redirect(admin_url('import'));
        }
        redirect(admin_url('import/' . $res));
    });
    $r->get('/import/{folder:[0-9a-f-]+}', static function (string $folder) use ($guard) {
        $guard();
        $pages = SiteImport::scan($folder);
        if (!$pages) {
            admin_404();
        }
        admin_view('import', ['step' => 'review', 'folder' => $folder, 'pages' => $pages, 'title' => 'Import pages']);
    });
    $r->post('/import/{folder:[0-9a-f-]+}', static function (string $folder) use ($guard) {
        $guard();
        @set_time_limit(300);
        $res = SiteImport::import($folder, ['paths' => array_map('strval', (array)input('paths', [])), 'status' => (string)input('status', 'draft'),
            'home' => (string)input('home', ''), 'type' => (string)input('type', 'page')]);
        if (!$res['ok']) {
            Session::flash('error', $res['error']);
            redirect(admin_url('import/' . $folder));
        }
        admin_view('import', ['step' => 'done', 'folder' => $folder, 'result' => $res['pages'], 'title' => 'Import pages']);
    });
};
