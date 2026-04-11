import LegalLayout from '@/layouts/legal-layout';

export default function Confidentialite() {
    return (
        <LegalLayout title="Politique de Confidentialité">
            <section className="mb-12">
                <h2 className="text-2xl font-bold mb-4">1. Collecte des données personnelles</h2>
                <p>
                    Nous collections les informations suivantes lors de votre inscription et utilisation du site :
                </p>
                <ul className="list-disc pl-6 mt-4 space-y-2 font-medium">
                    <li>Nom et adresse email (pour l’identification et la communication).</li>
                    <li>Mot de passe (stocké sous forme hachée, Mibeko n’a jamais accès à votre mot de passe en clair).</li>
                    <li>Données techniques (adresse IP, type de navigateur) via les cookies de session.</li>
                </ul>
            </section>

            <section className="mb-12 p-6 rounded-xl border border-blue-100 bg-blue-50 dark:bg-blue-900/10 dark:border-blue-900/30">
                <h2 className="text-2xl font-bold mb-4 text-[#0D47A1] dark:text-[#C5A059]">2. Intelligence Artificielle et Requêtes</h2>
                <p>
                    L’utilisation de l'Assistant IA Mibeko implique le traitement des questions que vous posez :
                </p>
                <ul className="list-disc pl-6 mt-4 space-y-2">
                    <li><strong>Anonymisation</strong> : Les questions posées à l’IA sont traitées de manière isolée de votre identité personnelle.</li>
                    <li><strong>Utilisation</strong> : Vos requêtes servent uniquement à générer des réponses juridiques pertinentes basées sur notre corpus documentaire.</li>
                    <li><strong>Non-entraînement</strong> : Mibeko s’engage à ne pas utiliser vos questions privées pour entraîner des modèles d’IA publics tiers sans votre consentement explicite.</li>
                </ul>
            </section>

            <section className="mb-12">
                <h2 className="text-2xl font-bold mb-4">3. Utilisation des données</h2>
                <p>
                    Vos données sont utilisées pour :
                </p>
                <ul className="list-disc pl-6 mt-4 space-y-2">
                    <li>Gérer votre compte et vos accès.</li>
                    <li>Améliorer nos algorithmes de recherche juridique.</li>
                    <li>Répondre à vos demandes de support technique via la page contact.</li>
                </ul>
            </section>

            <section className="mb-12">
                <h2 className="text-2xl font-bold mb-4">4. Partage des données</h2>
                <p>
                    Mibeko ne vend, ne loue, ni ne cède vos données personnelles à des tiers à des fins marketing.
                </p>
                <p className="mt-4">
                    Le partage de données ne se produit que dans les cas suivants :
                </p>
                <ul className="list-disc pl-6 mt-4 space-y-2">
                    <li>Prestataires techniques (hébergement, services mail) nécessaires au fonctionnement du service.</li>
                    <li>Conformité légale : sur demande des autorités judiciaires compétentes.</li>
                </ul>
            </section>

            <section className="mb-12">
                <h2 className="text-2xl font-bold mb-4">5. Vos droits (RGPD & Lois Locales)</h2>
                <p>
                    Conformément aux réglementations de protection des données, vous disposez des droits suivants :
                </p>
                <ul className="list-disc pl-6 mt-4 space-y-2">
                    <li><strong>Droit d'accès</strong> : Vous pouvez demander une copie de vos données personnelles.</li>
                    <li><strong>Droit de rectification</strong> : Vous pouvez corriger vos informations depuis votre profil.</li>
                    <li><strong>Droit à l'effacement</strong> : Vous pouvez demander la suppression définitive de votre compte et des données associées.</li>
                </ul>
                <p className="mt-6 text-sm">
                    Pour exercer ces droits, contactez-nous via le formulaire de contact ou à l’adresse <span className="underline font-bold">benja.bendo02@gmail.com</span>.
                </p>
            </section>
        </LegalLayout>
    );
}
