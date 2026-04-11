import LegalLayout from '@/layouts/legal-layout';

export default function CguCgv() {
    return (
        <LegalLayout title="Conditions Générales d'Utilisation (CGU / CGV)">
            <section className="mb-12">
                <h2 className="text-2xl font-bold mb-4">1. Objet</h2>
                <p>
                    Les présentes CGU régissent l'accès et l'utilisation de la plateforme <strong>Mibeko</strong>. En accédant au Site, vous acceptez sans réserve les présentes conditions.
                </p>
                <p className="mt-4 text-[#0D47A1] font-semibold dark:text-[#C5A059]">
                    Notre mission : Faciliter l'accès au savoir juridique par une recherche augmentée par l'IA.
                </p>
            </section>

            <section className="mb-12">
                <h2 className="text-2xl font-bold mb-4">2. Accès au Service</h2>
                <p>
                    Le Site est accessible gratuitement en consultation publique limitée. L'accès complet aux documents et aux fonctionnalités IA requiert la création d'un compte utilisateur.
                </p>
                <p className="mt-4">
                    Certaines fonctionnalités sont réservées aux utilisateurs ayant souscrit un abonnement payant (voir section Tarification).
                </p>
            </section>

            <section className="mb-12 border-l-4 border-[#C5A059] pl-6 py-4">
                <h2 className="text-2xl font-bold mb-4">3. Utilisation de l'Intelligence Artificielle</h2>
                <p>
                    Mibeko propose un assistant IA facilitant la recherche documentaire. L'utilisateur reconnaît que :
                </p>
                <ul className="list-disc pl-6 mt-4 space-y-2">
                    <li>L'IA peut parfois générer des interprétations qui ne sont pas des avis juridiques officiels.</li>
                    <li>Utilisation éthique : l'IA ne doit pas être utilisée pour des activités illicites ou malveillantes.</li>
                    <li>L'IA n'est pas un avocat : Mibeko ne remplace pas le conseil direct d'un professionnel du droit habilité.</li>
                </ul>
            </section>

            <section className="mb-12">
                <h2 className="text-2xl font-bold mb-4">4. Tarification et Facturation (CGV)</h2>
                <p>
                    Les tarifs des abonnements (Mensuels, Annuels ou par pack) sont indiqués TTC sur la plateforme de paiement Stripe ou via nos partenaires locaux.
                </p>
                <ul className="list-disc pl-6 mt-4 space-y-2">
                    <li><strong>Facturation</strong> : Les factures sont émises au nom de l'utilisateur ou de l'entité professionnelle.</li>
                    <li><strong>Droit de rétractation</strong> : Conformément à la nature numérique du service, l'accès immédiat au contenu dès le paiement peut limiter le droit de rétractation (selon les lois locales en vigueur).</li>
                    <li><strong>Renouvellement</strong> : Sauf résiliation, les abonnements peuvent être renouvelés tacitement.</li>
                </ul>
            </section>

            <section className="mb-12">
                <h2 className="text-2xl font-bold mb-4">5. Responsabilité et Garanties</h2>
                <p>
                    Mibeko s'engage à mettre en œuvre ses meilleurs efforts pour assurer la disponibilité du service. 
                </p>
                <p className="mt-4">
                    Toutefois, Mibeko ne saurait être tenu pour responsable en cas de dommages indirects résultant de l'utilisation des documents ou des réponses IA fournis sur la plateforme.
                </p>
            </section>

            <section className="mb-12 bg-slate-50 p-6 rounded-lg dark:bg-slate-900 border border-slate-100 dark:border-slate-800">
                <h2 className="text-2xl font-bold mb-4">6. Modification des conditions</h2>
                <p>
                    Mibeko se réserve le droit de modifier les présentes CGU/CGV à tout moment. Les utilisateurs seront informés par email ou via une notification sur le site en cas de modification substantielle.
                </p>
            </section>
        </LegalLayout>
    );
}
