@extends('layouts.pro_pdf')

@section('title', 'Mibeko — Reçu de confirmation')

@section('header_meta')
    Reçu de confirmation
@endsection

@php
    $billingInfo = $grant->user?->settings?->billing_info ?? [];
    $statusLabel = match ($grant->status()) {
        'revoked' => 'Accès retiré avant échéance',
        'ended' => 'Terminé',
        'scheduled' => 'À venir',
        default => 'Actif',
    };
    $channelLabel = match ($grant->channel) {
        'mobile_money' => 'Mobile Money',
        'bank_transfer', 'virement' => 'Virement bancaire',
        'cash', 'especes' => 'Espèces',
        default => $grant->channel ?? 'Non renseigné',
    };
@endphp

@section('content')
    <div style="text-align: center; margin-bottom: 22pt;">
        <div class="cover-badge">Reçu de confirmation</div>
        <h1 style="font-family: 'DejaVu Serif', Georgia, serif; font-size: 18pt; margin: 10pt 0 0 0; color: #18140c;">
            Abonnement Mibeko Pro
        </h1>
    </div>

    <div style="background-color: #fbf7ec; border: 0.75pt solid #d8c79b; border-radius: 3px; padding: 10pt 14pt; margin-bottom: 20pt; font-family: 'DejaVu Sans', Helvetica, sans-serif; font-size: 8.5pt; color: #6b5a2f; line-height: 1.5;">
        Ce document confirme un paiement enregistré et vérifié par l'équipe Mibeko.
        <strong>Ce n'est pas une facture</strong> : Mibeko n'exploite pas encore sous une entité
        juridique dotée d'un numéro d'identification fiscale, et ne peut donc émettre aucune pièce
        comptable conforme à ce jour.
    </div>

    <table class="cover-info-table" style="width: 100%; margin-bottom: 18pt;">
        <tr>
            <td class="label">Titulaire</td>
            <td>{{ $grant->user?->name }} ({{ $grant->user?->email }})</td>
        </tr>
        <tr>
            <td class="label">Statut</td>
            <td>{{ $statusLabel }}</td>
        </tr>
        <tr>
            <td class="label">Période contractuelle</td>
            <td>Du {{ $grant->starts_at->translatedFormat('d/m/Y') }} au {{ $grant->ends_at->translatedFormat('d/m/Y') }}</td>
        </tr>
        @if ($grant->revoked_at)
            <tr>
                <td class="label">Accès retiré le</td>
                <td>{{ $grant->revoked_at->translatedFormat('d/m/Y à H:i') }}</td>
            </tr>
        @endif
        <tr>
            <td class="label">Montant</td>
            <td>{{ $grant->amount_fcfa !== null ? number_format($grant->amount_fcfa, 0, ',', ' ').' FCFA' : 'Non renseigné' }}</td>
        </tr>
        <tr>
            <td class="label">Canal de paiement</td>
            <td>{{ $channelLabel }}</td>
        </tr>
        <tr>
            <td class="label">Référence de paiement</td>
            <td>{{ $grant->reference ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Référence Mibeko</td>
            <td>{{ $grant->id }}</td>
        </tr>
        <tr>
            <td class="label">Enregistré le</td>
            <td>{{ $grant->created_at->translatedFormat('d/m/Y à H:i') }}</td>
        </tr>
    </table>

    @if (! empty(array_filter($billingInfo)))
        <div style="margin-bottom: 18pt;">
            <div class="section-title" style="font-size: 9.5pt; margin: 0 0 8pt 0;">Informations de facturation déclarées par le titulaire</div>
            <table class="cover-info-table" style="width: 100%;">
                @if (! empty($billingInfo['company']))
                    <tr><td class="label">Société</td><td>{{ $billingInfo['company'] }}</td></tr>
                @endif
                @if (! empty($billingInfo['rccm']))
                    <tr><td class="label">RCCM</td><td>{{ $billingInfo['rccm'] }}</td></tr>
                @endif
                @if (! empty($billingInfo['tax_id']))
                    <tr><td class="label">NIF</td><td>{{ $billingInfo['tax_id'] }}</td></tr>
                @endif
                @if (! empty($billingInfo['address']))
                    <tr><td class="label">Adresse</td><td>{{ $billingInfo['address'] }}</td></tr>
                @endif
            </table>
            <p style="font-family: 'DejaVu Sans', Helvetica, sans-serif; font-size: 7.5pt; color: #8d8678; margin-top: 6pt;">
                Informations saisies par le titulaire lui-même, non vérifiées par Mibeko.
            </p>
        </div>
    @endif

    <p style="font-family: 'DejaVu Sans', Helvetica, sans-serif; font-size: 8.5pt; color: #8d8678; margin-top: 24pt;">
        Cet abonnement ne se renouvelle pas automatiquement. Un rappel est envoyé par e-mail avant
        son échéance ; le renouvellement reste à convenir avec l'équipe Mibeko.
    </p>

    <div class="certification">
        Document généré automatiquement — Mibeko, Le Droit numérique
        <div class="cert-id">Généré le {{ now()->translatedFormat('d/m/Y à H:i') }} — {{ $grant->id }}</div>
    </div>
@endsection
