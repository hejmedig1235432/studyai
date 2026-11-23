<?php
declare(strict_types=1);

use App\Admin;
use App\Auth;
use App\Cards;
use App\Guide;
use App\Http;
use App\HttpError;
use App\Learn;
use App\Pipeline;
use App\Progress;
use App\Quiz;
use App\Sources;
use App\Subjects;
use App\Support;
use App\Tutor;

ini_set('display_errors', '0');
@set_time_limit(300);

// Fatal errors (e.g. time limit) can't be caught: log them and still answer with readable JSON.
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e === null || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    @file_put_contents(dirname(__DIR__) . '/storage/logs/app.log', '[' . date('c') . '] FATAL: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line'] . PHP_EOL, FILE_APPEND);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    $msg = str_contains($e['message'], 'Maximum execution time')
        ? 'AI’en var for længe om at svare. Prøv igen (gratis modeller kan være langsomme).'
        : 'Der gik noget galt på serveren. Prøv igen. (Detaljer står i storage/logs/app.log)';
    echo json_encode(['error' => $msg]);
});

try {
    require dirname(__DIR__) . '/src/bootstrap.php';
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Serveren kunne ikke starte: ' . $e->getMessage()]);
    exit;
}

/** Routes that work without being signed in. Everything else requires login. */
const PUBLIC_ROUTES = ['auth.me', 'auth.register', 'auth.login', 'auth.logout', 'auth.forgot', 'auth.reset', 'share.info'];

/** route => [HTTP method, handler, requires login (informational – see PUBLIC_ROUTES)] */
$routes = [
    'auth.me' => ['GET', static fn () => ['user' => Auth::publicUser(Auth::user()), 'csrf' => Auth::csrf(), 'device' => App\Security::deviceId(), 'ban' => App\Security::deviceBan()], false],
    'auth.register' => ['POST', [Auth::class, 'register'], false],
    'auth.login' => ['POST', [Auth::class, 'signIn'], false],
    'auth.logout' => ['POST', [Auth::class, 'logout'], false],
    'auth.forgot' => ['POST', [Auth::class, 'forgot'], false],
    'auth.reset' => ['POST', [Auth::class, 'reset'], false],
    'auth.profile' => ['POST', [Auth::class, 'updateProfile'], true],
    'auth.password' => ['POST', [Auth::class, 'changePassword'], true],
    'auth.delete' => ['POST', [Auth::class, 'deleteAccount'], true],

    'dashboard' => ['GET', [Progress::class, 'dashboard'], true],
    'progress.heartbeat' => ['POST', [Progress::class, 'heartbeat'], true],
    'progress.subject' => ['GET', [Progress::class, 'subject'], true],

    'subjects.list' => ['GET', [Subjects::class, 'list'], true],
    'subjects.create' => ['POST', [Subjects::class, 'create'], true],
    'subjects.get' => ['GET', [Subjects::class, 'get'], true],
    'subjects.update' => ['POST', [Subjects::class, 'update'], true],
    'subjects.delete' => ['POST', [Subjects::class, 'delete'], true],
    'subjects.process' => ['POST', [Subjects::class, 'process'], true],

    'sources.list' => ['GET', [Sources::class, 'list'], true],
    'sources.get' => ['GET', [Sources::class, 'get'], true],
    'sources.upload' => ['POST', [Sources::class, 'upload'], true],
    'sources.url' => ['POST', [Sources::class, 'addUrl'], true],
    'sources.text' => ['POST', [Sources::class, 'addText'], true],
    'sources.rename' => ['POST', [Sources::class, 'rename'], true],
    'sources.retry' => ['POST', [Sources::class, 'retry'], true],
    'sources.delete' => ['POST', [Sources::class, 'delete'], true],
    'sources.limits' => ['GET', [Sources::class, 'limits'], true],

    'jobs.run' => ['POST', [Pipeline::class, 'runApi'], true],
    'jobs.status' => ['GET', [Pipeline::class, 'statusApi'], true],

    'guide.get' => ['GET', [Guide::class, 'get'], true],
    'guide.save' => ['POST', [Guide::class, 'save'], true],
    'guide.add' => ['POST', [Guide::class, 'addNote'], true],
    'guide.delete' => ['POST', [Guide::class, 'delete'], true],
    'guide.transform' => ['POST', [Guide::class, 'transform'], true],
    'guide.regenerate' => ['POST', [Guide::class, 'regenerate'], true],
    'guide.summary' => ['POST', [Guide::class, 'summary'], true],
    'guide.markdown' => ['GET', [Guide::class, 'exportMarkdown'], true],
    'guide.read' => ['POST', [Guide::class, 'markRead'], true],

    'cards.list' => ['GET', [Cards::class, 'list'], true],
    'cards.session' => ['GET', [Cards::class, 'session'], true],
    'cards.review' => ['POST', [Cards::class, 'review'], true],
    'cards.generate' => ['POST', [Cards::class, 'generate'], true],
    'cards.save' => ['POST', [Cards::class, 'save'], true],
    'cards.delete' => ['POST', [Cards::class, 'delete'], true],
    'cards.reset' => ['POST', [Cards::class, 'reset'], true],
    'cards.export' => ['GET', [Cards::class, 'export'], true],
    'memory' => ['GET', [Cards::class, 'memory'], true],

    'quiz.start' => ['POST', [Quiz::class, 'start'], true],
    'quiz.answer' => ['POST', [Quiz::class, 'answer'], true],
    'quiz.finish' => ['POST', [Quiz::class, 'finish'], true],
    'quiz.results' => ['GET', [Quiz::class, 'resultsApi'], true],
    'quiz.history' => ['GET', [Quiz::class, 'history'], true],
    'quiz.review' => ['GET', [Quiz::class, 'reviewQuestions'], true],

    'exam.create' => ['POST', [Quiz::class, 'examCreate'], true],
    'exam.get' => ['GET', [Quiz::class, 'examGet'], true],
    'exam.begin' => ['POST', [Quiz::class, 'examBegin'], true],
    'exam.save' => ['POST', [Quiz::class, 'examSave'], true],
    'exam.submit' => ['POST', [Quiz::class, 'examSubmit'], true],

    'tutor.threads' => ['GET', [Tutor::class, 'threads'], true],
    'tutor.messages' => ['GET', [Tutor::class, 'messages'], true],
    'tutor.send' => ['POST', [Tutor::class, 'send'], true],
    'tutor.stream' => ['POST', [Tutor::class, 'stream'], true],
    'tutor.delete' => ['POST', [Tutor::class, 'deleteThread'], true],

    'learn.start' => ['POST', [Learn::class, 'start'], true],
    'learn.get' => ['GET', [Learn::class, 'get'], true],
    'learn.latest' => ['GET', [Learn::class, 'latest'], true],
    'learn.answer' => ['POST', [Learn::class, 'answer'], true],
    'learn.action' => ['POST', [Learn::class, 'action'], true],

    'search' => ['GET', [Admin::class, 'search'], true],
    'updates' => ['GET', [App\Changelog::class, 'list'], true],
    'usage' => ['GET', [App\Usage::class, 'meter'], true],
    'plan.get' => ['GET', [App\Plan::class, 'get'], true],
    'plan.generate' => ['POST', [App\Plan::class, 'generate'], true],
    'plan.toggle' => ['POST', [App\Plan::class, 'toggle'], true],
    'admin.overview' => ['GET', [Admin::class, 'overview'], true],
    'admin.user' => ['POST', [Admin::class, 'updateUser'], true],
    'admin.user.delete' => ['POST', [Admin::class, 'deleteUser'], true],
    'admin.user.password' => ['POST', [Admin::class, 'setPassword'], true],
    'admin.user_limit' => ['POST', [Admin::class, 'setUserLimit'], true],
    'share.status' => ['GET', [App\Share::class, 'status'], true],
    'share.toggle' => ['POST', [App\Share::class, 'toggle'], true],
    'share.info' => ['GET', [App\Share::class, 'info'], false],
    'share.copy' => ['POST', [App\Share::class, 'copy'], true],

    'essay.list' => ['GET', [App\Essay::class, 'list'], true],
    'essay.get' => ['GET', [App\Essay::class, 'get'], true],
    'essay.review' => ['POST', [App\Essay::class, 'review'], true],
    'essay.review.stream' => ['POST', [App\Essay::class, 'reviewStream'], true],
    'essay.extract' => ['POST', [App\Essay::class, 'extract'], true],
    'essay.delete' => ['POST', [App\Essay::class, 'delete'], true],
    'updates.seen' => ['POST', [App\Changelog::class, 'seen'], true],
    'ai.status' => ['GET', [Admin::class, 'aiStatus'], true],
    'ai.key.add' => ['POST', [Admin::class, 'addKey'], true],
    'ai.key.update' => ['POST', [Admin::class, 'updateKey'], true],
    'ai.keys.test' => ['POST', [Admin::class, 'testKeys'], true],
    'ai.config' => ['POST', [Admin::class, 'updateConfig'], true],
    'ai.reindex' => ['POST', [Admin::class, 'reindex'], true],
    'ai.free_models' => ['POST', [Admin::class, 'useFreeModels'], true],
];

try {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    Auth::startSession();
    $route = (string) ($_GET['r'] ?? '');
    if (!isset($routes[$route])) {
        throw new HttpError(404, 'Ukendt API-adresse.');
    }
    [$method, $handler] = $routes[$route];
    if (Http::method() !== $method) {
        throw new HttpError(405, 'Metoden er ikke tilladt.');
    }
    // Banned devices (account + device ban, never IP) can't use the service.
    if ($route !== 'auth.me' && $route !== 'auth.logout') {
        App\Security::guardDevice();
    }
    // Rate limit every request (per user when signed in, per IP otherwise).
    App\RateLimit::check($route, Auth::user() ? (int) Auth::user()['id'] : null);
    if ($method === 'POST') {
        Auth::checkCsrf();
    }
    // Login is required for EVERY route except the few on this list – a new route is protected by default.
    if (!in_array($route, PUBLIC_ROUTES, true)) {
        Auth::require();
    }
    // Administrator routes are checked here as well as inside their handlers.
    if ((str_starts_with($route, 'admin.') || str_starts_with($route, 'ai.')) && $route !== 'ai.status' && !Auth::isAdmin()) {
        throw new HttpError(403, 'Kun administratoren har adgang til dette.');
    }
    // Release the session lock immediately (only auth.* routes write to the session). PHP locks the
    // session file for the whole request, which would make every other request of this user wait.
    if (!str_starts_with($route, 'auth.')) {
        Auth::release();
    }
    Http::json($handler());
} catch (HttpError $e) {
    Http::json(['error' => $e->getMessage(), 'code' => $e->code_], $e->status);
} catch (Throwable $e) {
    Support::log('Unhandled: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
    Http::json(['error' => 'Der gik noget galt på serveren. Prøv igen. (Detaljer står i storage/logs/app.log)'], 500);
}
