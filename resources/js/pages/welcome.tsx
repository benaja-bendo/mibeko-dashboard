import AppLogoIcon from '@/components/app-logo-icon';
import { dashboard, login, register } from '@/routes';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

export default function Welcome({
    canRegister = true,
}: {
    canRegister?: boolean;
}) {
    const { auth } = usePage<SharedData>().props;

    return (
        <>
            <Head title="Bienvenue">
                <link rel="preconnect" href="https://fonts.googleapis.com" />
                <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
            </Head>
            <div className="flex min-h-screen flex-col bg-white font-['Outfit',sans-serif] text-slate-900 dark:bg-slate-950 dark:text-slate-100">
                {/* Header/Nav */}
                <header className="mx-auto flex h-20 w-full max-w-7xl items-center justify-between px-6">
                    <div className="flex items-center gap-2">
                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-[#0D47A1] text-white shadow-lg">
                            <AppLogoIcon className="size-6 fill-current" />
                        </div>
                        <span className="text-xl font-bold tracking-tight text-[#0D47A1] dark:text-white">MIBEKO</span>
                    </div>

                    <nav className="flex items-center gap-6">
                        {auth.user ? (
                            <Link
                                href={dashboard()}
                                className="rounded-full bg-[#0D47A1] px-5 py-2 text-sm font-semibold text-white shadow-md transition-all hover:bg-[#0a3a85] hover:shadow-lg active:scale-95"
                            >
                                Vers le Dashboard
                            </Link>
                        ) : (
                            <>
                                <Link
                                    href={login()}
                                    className="text-sm font-medium transition-colors hover:text-[#0D47A1] dark:hover:text-[#C5A059]"
                                >
                                    Se connecter
                                </Link>
                                {canRegister && (
                                    <Link
                                        href={register()}
                                        className="rounded-full bg-[#0D47A1] px-5 py-2 text-sm font-semibold text-white shadow-md transition-all hover:bg-[#0a3a85] hover:shadow-lg active:scale-95 dark:bg-[#C5A059] dark:text-slate-950 dark:hover:bg-[#b08d4b]"
                                    >
                                        Créer un compte
                                    </Link>
                                )}
                            </>
                        )}
                    </nav>
                </header>

                <main className="relative flex flex-1 flex-col items-center justify-center px-6 text-center">
                    {/* Background Decorative Element */}
                    <div className="absolute top-1/2 left-1/2 -z-10 h-[500px] w-[500px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-[#0D47A1]/5 blur-[120px] dark:bg-[#C5A059]/10" />

                    <div className="max-w-3xl space-y-8">
                        <div className="inline-flex items-center rounded-full border border-slate-200 bg-white/50 px-3 py-1 text-xs font-semibold text-[#0D47A1] backdrop-blur-sm dark:border-slate-800 dark:bg-slate-900/50 dark:text-[#C5A059]">
                            <span className="mr-2 flex h-2 w-2 rounded-full bg-[#C5A059] animate-pulse" />
                            Portail Officiel Mibeko
                        </div>

                        <h1 className="text-5xl font-extrabold tracking-tight sm:text-7xl">
                            L'excellence juridique <br />
                            <span className="bg-gradient-to-r from-[#0D47A1] to-[#C5A059] bg-clip-text text-transparent">
                                à votre portée.
                            </span>
                        </h1>

                        <p className="mx-auto max-w-xl text-lg text-slate-600 sm:text-xl dark:text-slate-400">
                            Accédez à la base de données juridique la plus complète et fiable. 
                            Une gestion simplifiée pour les professionnels du droit.
                        </p>

                        <div className="flex flex-col items-center justify-center gap-4 sm:flex-row">
                            {!auth.user && (
                                <Link
                                    href={register()}
                                    className="w-full rounded-full bg-[#0D47A1] px-8 py-4 text-lg font-bold text-white shadow-xl transition-all hover:bg-[#0a3a85] hover:shadow-2xl active:scale-95 sm:w-auto"
                                >
                                    Commencer maintenant
                                </Link>
                            )}
                            <button
                                className="w-full rounded-full border border-slate-200 bg-white px-8 py-4 text-lg font-semibold shadow-sm transition-all hover:bg-slate-50 active:scale-95 sm:w-auto dark:border-slate-800 dark:bg-slate-900 dark:hover:bg-slate-800"
                            >
                                En savoir plus
                            </button>
                        </div>
                    </div>
                </main>

                <footer className="mx-auto w-full max-w-7xl p-6 text-center text-sm text-slate-500">
                    <div className="flex flex-col items-center justify-between gap-4 border-t border-slate-100 py-8 sm:flex-row dark:border-slate-900">
                        <p>© {new Date().getFullYear()} Mibeko. Tous droits réservés.</p>
                        <div className="flex gap-6">
                            <Link href="/mentions-legales" className="hover:text-[#0D47A1]">Mentions Légales</Link>
                            <Link href="/confidentialite" className="hover:text-[#0D47A1]">Confidentialité</Link>
                            <Link href="/cgu-cgv" className="hover:text-[#0D47A1]">CGU / CGV</Link>
                            <Link href="/contact" className="hover:text-[#0D47A1]">Contact</Link>
                        </div>
                    </div>
                </footer>
            </div>
        </>
    );
}
