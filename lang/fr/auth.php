<?php

return [

    'failed' => 'Les identifiants fournis sont incorrects.',
    'password' => 'Le mot de passe est incorrect.',
    'throttle' => 'Trop de tentatives de connexion. Réessayez dans :seconds secondes.',

    /*
     * Inscription avec une adresse déjà prise (mibeko-dashboard#187) : la
     * personne a souvent déjà un compte — pendant la panne SMTP du 21 au
     * 24/09/2026, 12 inscriptions ont créé un compte en affichant une erreur.
     * On l'oriente vers la connexion plutôt que de lui opposer un refus sec.
     */
    'email_deja_inscrit' => 'Un compte existe déjà avec cette adresse e-mail. Connectez-vous, ou utilisez « Mot de passe oublié » si vous ne vous en souvenez plus.',

];
