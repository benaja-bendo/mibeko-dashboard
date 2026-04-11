import AppLogoIcon from '@/components/app-logo-icon';
import { home, login } from '@/routes';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import React from 'react';

interface LegalLayoutProps {
    title: string;
    children: React.ReactNode;
}

export default function LegalLayout({ title, children }: LegalLayoutProps) {
    const { auth } = usePage<SharedData>().props;

    return (
        <div className="min-h-screen bg-slate-50 font-sans text-slate-900 dark:bg-slate-950 dark:text-slate-100">
            <Head title={title} />
            
            {/* Minimal Header */}
            <header className="sticky top-0 z-50 border-b border-slate-200 bg-white/80 backdrop-blur-md dark:border-slate-800 dark:bg-slate-900/80">
                <div className="mx-auto flex h-16 max-w-7xl items-center justify-between px-6">
                    <Link href={home()} className="flex items-center gap-2 transition-opacity hover:opacity-80">
                        <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-[#0D47A1] text-white">
                            <AppLogoIcon className="size-5 fill-current" />
                        </div>
                        <span className="text-lg font-bold tracking-tight text-[#0D47A1] dark:text-white">MIBEKO</span>
                    </Link>

                    <div className="flex items-center gap-4">
                        {!auth.user && (
                            <Link href={login()} className="text-sm font-medium hover:text-[#0D47A1]">
                                Connexion
                            </Link>
                        )}
                        <Link href={home()} className="rounded-full border border-slate-200 px-4 py-1.5 text-sm font-medium transition-colors hover:bg-slate-100 dark:border-slate-800 dark:hover:bg-slate-900">
                            Retour à l'accueil
                        </Link>
                    </div>
                </div>
            </header>

            {/* Main Content */}
            <main className="mx-auto max-w-4xl px-6 py-12 sm:py-20">
                <article className="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm sm:p-12 dark:border-slate-800 dark:bg-slate-900">
                    <h1 className="mb-8 text-3xl font-extrabold tracking-tight sm:text-4xl text-[#0D47A1] dark:text-[#C5A059]">
                        {title}
                    </h1>
                    
                    <div className="prose prose-slate max-w-none dark:prose-invert prose-headings:text-[#0D47A1] dark:prose-headings:text-[#C5A059] prose-a:text-[#0D47A1] hover:prose-a:underline">
                        {children}
                    </div>
                </article>
            </main>

            {/* Simple Footer */}
            <footer className="border-t border-slate-200 py-8 text-center text-sm text-slate-500 dark:border-slate-800">
                <p>© {new Date().getFullYear()} Mibeko. Tous droits réservés.</p>
            </footer>
        </div>
    );
}
