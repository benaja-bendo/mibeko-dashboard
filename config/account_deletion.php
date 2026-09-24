<?php

return [

    /*
     * Délai entre la suppression d'un compte (soft delete, par l'usager via
     * `DELETE /v1/profile` ou par un admin) et son effacement définitif par
     * `mibeko:purger-comptes-supprimes` — décision du 24/09/2026
     * (docs/decisions.md). Pendant ce délai, un admin peut restaurer le compte
     * (`POST /v1/admin/users/{id}/restore`) et l'adresse reste prise à la
     * réinscription (index unique `users_email_key`).
     *
     * Volontairement sans variable d'environnement : la politique de
     * confidentialité (mibeko-site, § 4) promet qu'aucune copie ne subsiste
     * 90 jours après la demande. Ce délai plus l'âge maximal d'une sauvegarde
     * (`config/backup.php`, cleanup) doit rester sous cette borne, et
     * `PurgerComptesSupprimesTest` le vérifie. Le changer, c'est changer la
     * page publique dans le même mouvement.
     */
    'purge_after_days' => 30,

];
