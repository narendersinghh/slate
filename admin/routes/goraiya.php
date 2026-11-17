<?php
/** Goraiya, the AI drawer (core/Slate/Goraiya.php). */
use Slate\Auth;
use Slate\Goraiya;
use Slate\Router;

return static function (Router $r): void {
    $r->post('/goraiya', static function () {
        Auth::require('edit_posts');
        Auth::require('use_ai');
        @set_time_limit(320);
        $in = json_input();
        $res = Goraiya::chat((string)($in['message'] ?? ''), array_values(array_filter((array)($in['history'] ?? []), 'is_array')), (array)($in['context'] ?? []));
        json_response($res, $res['ok'] ? 200 : 422);
    });
    $r->post('/goraiya/connect', static function () {
        $in = json_input();
        @set_time_limit(90);
        $res = Goraiya::connect((string)($in['provider'] ?? ''), trim((string)($in['key'] ?? '')), trim((string)($in['model'] ?? '')));
        json_response($res, $res['ok'] ? 200 : 422);
    });
};
