import LegalLayout from '@/layouts/legal-layout';

export default function MentionsLegales() {
    return (
        <LegalLayout title="Mentions Légales">
            <section className="mb-12">
                <h2 className="text-2xl font-bold mb-4">1. Édition du site</h2>
                <p>
                    Le présent site, accessible à l'URL <span className="font-semibold italic">app.mibeko.fr</span> (le « Site »), est édité par :
                </p>
                <ul className="list-disc pl-6 mt-4 space-y-2">
                    <li><strong>Propriétaire :</strong> Bénaja Bendo</li>
                    <li><strong>Responsable de la publication :</strong> Bénaja Bendo</li>
                    <li><strong>Contact :</strong> benja.bendo02@gmail.com</li>
                </ul>
            </section>

            <section className="mb-12">
                <h2 className="text-2xl font-bold mb-4">2. Hébergement</h2>
                <p>
                    Le Site est hébergé par :
                </p>
                <div className="mt-4 p-4 rounded-lg bg-slate-50 border border-slate-100 dark:bg-slate-800 dark:border-slate-700">
                    <p><strong>infomaniak</strong></p>
                    <p>411 RUE DE PICARDIE</p>
                    <p>60170 RIBECOURT-DRESLINCOURT</p>
                </div>
            </section>

            <section className="mb-12">
                <h2 className="text-2xl font-bold mb-4">3. Propriété intellectuelle</h2>
                <p>
                    Mibeko est propriétaire des droits de propriété intellectuelle ou détient les droits d’usage sur tous les éléments accessibles sur le site, notamment les textes, images, graphismes, logos, icônes, sons et logiciels.
                </p>
                <p className="mt-4 italic text-sm text-slate-500">
                    Toute reproduction, représentation, modification, publication, adaptation de tout ou partie des éléments du site, quel que soit le moyen ou le procédé utilisé, est interdite, sauf autorisation écrite préalable de Mibeko.
                </p>
            </section>

            <section className="mb-12">
                <h2 className="text-2xl font-bold mb-4">4. Limitation de responsabilité</h2>
                <p>
                    Mibeko s’efforce de fournir sur le site des informations aussi précises que possible. Toutefois, Mibeko ne pourra être tenu responsable des omissions, des inexactitudes et des carences dans la mise à jour, qu’elles soient de son fait ou du fait des tiers partenaires qui lui fournissent ces informations.
                </p>
                <p className="mt-4">
                    Les bases de données juridiques sont fournies à titre informatif et ne sauraient remplacer le conseil d'un professionnel du droit.
                </p>
            </section>

            <section className="mb-12">
                <h2 className="text-2xl font-bold mb-4">5. Droit applicable</h2>
                <p>
                    Tout litige en relation avec l’utilisation du site est soumis au droit en vigueur dans votre juridiction de référence (République du Congo).
                </p>
            </section>
        </LegalLayout>
    );
}
