@extends('auth.verification-layout')

@section('title', 'Lien expiré')

@section('content')
    <h1>Ce lien a expiré</h1>
    <p>Un lien de confirmation n'est valable que {{ $duree }}. Votre compte existe bien : il suffit d'en demander un nouveau.</p>
    <p>Ouvrez l'application Mibeko et touchez « Renvoyer l'e-mail de vérification » sur l'accueil. Un nouveau lien vous est envoyé aussitôt.</p>
    <p>Un souci ? Écrivez-nous à <a href="mailto:contact@mibeko.fr">contact@mibeko.fr</a>.</p>
@endsection
