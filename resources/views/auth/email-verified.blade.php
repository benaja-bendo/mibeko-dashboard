@extends('auth.verification-layout')

@section('title', 'Adresse e-mail vérifiée')

@section('content')
    <h1>Adresse e-mail vérifiée</h1>
    <p>Votre compte Mibeko est prêt. Vous pouvez revenir dans l'application, ou continuer sur le web.</p>
    <a class="bouton" href="{{ $frontendUrl }}">Continuer sur le web</a>
@endsection
