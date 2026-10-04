<?php

use App\Http\Controllers\MediaController;
use App\Http\Controllers\PdfProxyController;
use App\Http\Controllers\ShareController;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

// Racine de l'hôte API : page de statut « machine » (pas de vitrine, pas
// d'authentification ici). Les humains vont sur mibeko.fr / app.mibeko.fr.
Route::get('/', function (Request $request) {
    $payload = [
        'service' => config('app.name', 'Mibeko').' API',
        'status' => 'ok',
        'version' => 'v1',
        'documentation' => url('/docs/api'),
        'application' => 'https://app.mibeko.fr',
        'website' => 'https://mibeko.fr',
        'health' => url('/up'),
    ];

    if ($request->expectsJson()) {
        return response()->json($payload);
    }

    return response()->view('status', $payload);
})->name('home');

// Public routes for Deep Linking / Web Share (ShareController : comportement,
// anonymat et canonique y sont documentés). Pas de closure ici qui appelle une
// fonction déclarée dans ce fichier : `route:cache` ne le recharge pas en
// production (mibeko-dashboard#241).
Route::get('/article/{articleId}', [ShareController::class, 'article'])->name('share.article');
Route::get('/document/{documentId}', [ShareController::class, 'document'])->name('share.document');

Route::middleware(['auth', 'verified'])->group(function () {

    // PDF Proxy for inline display (fixes Minio/S3 download issue)
    Route::get('/pdf-proxy/{id}', [PdfProxyController::class, 'show'])->name('pdf.proxy');

    // Media Management
    Route::get('/api/media/files', [MediaController::class, 'listAvailableFiles'])->name('api.media.files');
    Route::post('/curation/{document}/attach-media', [MediaController::class, 'attachFile'])->name('curation.attach-media');
});
// La signature est contrôlée ici et non par le middleware `signed` : celui-ci
// répond « 403 Invalid signature. », en anglais et sans issue, pour un lien
// simplement expiré — la page qu'ont vue les usagers relancés le 24/09/2026
// après la panne SMTP (mibeko-dashboard#206). On distingue désormais un lien
// invalide (signature ou empreinte fausse) d'un lien authentique mais expiré.
Route::get('/email/verify/{id}/{hash}', function (Request $request, string $id, string $hash) {
    $vue = [
        'frontendUrl' => rtrim((string) config('app.frontend_url'), '/').'/auth/verifier-email',
        'duree' => VerifyEmailNotification::dureeDeValidite((int) config('auth.verification.expire', 60)),
    ];

    // Signature contrôlée AVANT toute requête : l'identifiant n'est lu en
    // base que s'il est authentique.
    $user = URL::hasCorrectSignature($request) ? User::find($id) : null;

    if ($user === null || ! hash_equals($hash, sha1($user->getEmailForVerification()))) {
        return response()->view('auth.email-verification-invalid', $vue, 403);
    }

    // Un lien authentique, même expiré, prouve la possession de l'adresse :
    // si elle est déjà vérifiée, on le dit plutôt que d'afficher une erreur.
    if ($user->hasVerifiedEmail()) {
        return response()->view('auth.email-verified', $vue);
    }

    if (! URL::signatureHasNotExpired($request)) {
        return response()->view('auth.email-verification-expired', $vue, 403);
    }

    $user->markEmailAsVerified();
    event(new Verified($user));

    return response()->view('auth.email-verified', $vue);
})->middleware('throttle:6,1')->name('verification.verify');
