@extends('auth.verification-layout')

@section('title', 'Lien non valide')

@section('content')
    <h1>Ce lien n'est pas valide</h1>
    <p>Il a peut-être été coupé en le copiant, ou il appartient à un compte qui n'existe plus.</p>
    <p>Ouvrez l'application Mibeko et touchez « Renvoyer l'e-mail de vérification » sur l'accueil, puis utilisez le lien du nouvel e-mail.</p>
    <p>Un souci ? Écrivez-nous à <a href="mailto:contact@mibeko.fr">contact@mibeko.fr</a>.</p>
@endsection
