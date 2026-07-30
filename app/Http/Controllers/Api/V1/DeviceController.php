<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Traits\HttpResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DeviceController extends Controller
{
    use HttpResponses;

    /**
     * Register or update a device token.
     *
     * La route reste PUBLIQUE : l'app mobile enregistre son jeton FCM au
     * démarrage, avant toute connexion, et un invité doit pouvoir recevoir la
     * veille légale générale. Quand un jeton Sanctum accompagne malgré tout la
     * requête, l'appareil est rattaché à son propriétaire — c'est ce lien qui
     * permet ensuite de respecter ses préférences de notification.
     *
     * `app_version` est OPTIONNEL : l'app publiée sur les stores (v1.0/v1.1) ne
     * l'envoie pas, et son enregistrement doit continuer de passer. Quand elle
     * est fournie (v1.2+), elle décide de la forme du message push servi à cet
     * appareil (cf. `SendLegalWatchNotifications`).
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'device_id' => 'required|string|max:255',
            'push_token' => 'required|string|max:255',
            'platform' => 'required|in:android,ios',
            // Semver toléré : préfixe `v`, 1 à 4 segments, suffixe de
            // pré-version ou métadonnées de build (cf. `AppVersion`). Une valeur
            // hors format est refusée plutôt que stockée : elle serait de toute
            // façon illisible au moment de choisir la forme du push.
            'app_version' => ['nullable', 'string', 'max:20', 'regex:/^v?\d+(\.\d+){0,3}(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$/'],
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors(), $validator->errors()->first(), 422);
        }

        // `user('sanctum')` et non `user()` : la route est hors du groupe
        // `auth:sanctum`, le garde par défaut (web/session) n'y voit personne.
        $user = $request->user('sanctum');

        $attributes = [
            'push_token' => $request->push_token,
            'platform' => $request->platform,
            // Écrit tel que déclaré, `null` compris : un enregistrement sans
            // version efface une valeur précédente. C'est le sens sûr — la
            // version inconnue retombe sur le format de push le plus largement
            // affichable, là où conserver une valeur périmée (app réinstallée en
            // version antérieure) enverrait des alertes invisibles.
            'app_version' => $request->input('app_version'),
            'status' => 'active',
            'last_registered_at' => now(),
        ];

        // Un appareil déjà rattaché n'est jamais « détaché » par un
        // ré-enregistrement anonyme : l'app peut renvoyer son jeton avant que la
        // session ne soit restaurée, ce qui perdrait le lien à chaque démarrage.
        if ($user !== null) {
            $attributes['user_id'] = $user->id;
        }

        $device = Device::updateOrCreate(
            ['device_id' => $request->device_id],
            $attributes
        );

        return $this->success($device, 'Appareil enregistré avec succès.');
    }

    /**
     * Unregister a device (set status to inactive).
     *
     * Reste ouverte aux invités : un appareil sans propriétaire doit pouvoir se
     * retirer de la veille générale. Quand l'appelant est authentifié, il ne
     * peut désinscrire qu'un appareil libre ou le sien — sans quoi un jeton
     * valide suffirait à couper les notifications de n'importe quel appareil
     * dont on devine l'identifiant.
     */
    public function unregister(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'device_id' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors(), $validator->errors()->first(), 422);
        }

        $device = Device::where('device_id', $request->device_id)->first();
        $user = $request->user('sanctum');

        if ($device && $device->user_id !== null && $device->user_id !== $user?->id) {
            return $this->error(null, 'Appareil non trouvé.', 404);
        }

        if ($device) {
            $device->update(['status' => 'inactive']);

            return $this->success(null, 'Appareil désinscrit avec succès.');
        }

        return $this->error(null, 'Appareil non trouvé.', 404);
    }
}
