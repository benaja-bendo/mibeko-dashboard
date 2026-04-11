import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import { Head, Link, useForm } from '@inertiajs/react';
import { Mail, MapPin, Send } from 'lucide-react';

export default function Contact() {
    const { data, setData, post, processing, errors, reset, wasSuccessful } = useForm({
        name: '',
        email: '',
        subject: '',
        message: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        // Pour l'instant, on simule l'envoi ou on redirige vers une route de support si elle existe
        // post('/contact-submit', { preserveScroll: true, onSuccess: () => reset() });
        console.log('Message envoyé:', data);
    };

    return (
        <div className="min-h-screen bg-white font-sans text-slate-900 dark:bg-slate-950 dark:text-slate-100">
            <Head title="Contactez-nous" />
            
            <header className="mx-auto flex h-20 w-full max-w-7xl items-center justify-between px-6">
                <Link href={home()} className="flex items-center gap-2">
                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-[#0D47A1] text-white">
                        <AppLogoIcon className="size-6 fill-current" />
                    </div>
                    <span className="text-xl font-bold tracking-tight text-[#0D47A1] dark:text-white">MIBEKO</span>
                </Link>
            </header>

            <main className="mx-auto max-w-5xl px-6 py-12 sm:py-24">
                <div className="grid gap-12 lg:grid-cols-2">
                    {/* Left Side: Info */}
                    <div className="space-y-8">
                        <div>
                            <h1 className="text-4xl font-extrabold tracking-tight sm:text-5xl text-[#0D47A1] dark:text-white">
                                Contactez l'équipe Mibeko.
                            </h1>
                            <p className="mt-4 text-lg text-slate-600 dark:text-slate-400">
                                Une question technique ? Un besoin de support juridique ? 
                                Nous sommes là pour vous aider.
                            </p>
                        </div>

                        <div className="space-y-6">
                            <div className="flex items-start gap-4">
                                <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-[#0D47A1] dark:bg-blue-900/20 dark:text-[#C5A059]">
                                    <Mail className="size-6" />
                                </div>
                                <div>
                                    <h3 className="font-bold">Email</h3>
                                    <p className="text-slate-600 dark:text-slate-400">support@mibeko.cd</p>
                                </div>
                            </div>

                            <div className="flex items-start gap-4">
                                <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-gold-50 text-[#C5A059] dark:bg-gold-900/20">
                                    <MapPin className="size-6" />
                                </div>
                                <div>
                                    <h3 className="font-bold">Bureaux</h3>
                                    <p className="text-slate-600 dark:text-slate-400">Kinshasa, République Démocratique du Congo</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Right Side: Form */}
                    <div className="rounded-3xl border border-slate-200 bg-slate-50/50 p-8 dark:border-slate-800 dark:bg-slate-900/50 backdrop-blur-sm">
                        {wasSuccessful ? (
                            <div className="flex flex-col items-center justify-center py-12 text-center">
                                <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-green-100 text-green-600">
                                    <Send className="size-8" />
                                </div>
                                <h3 className="text-2xl font-bold">Message envoyé !</h3>
                                <p className="mt-2 text-slate-600">Nous vous répondrons dans les plus brefs délais.</p>
                                <button onClick={() => reset()} className="mt-6 text-[#0D47A1] font-semibold underline">Envoyer un autre message</button>
                            </div>
                        ) : (
                            <form onSubmit={handleSubmit} className="space-y-6">
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <div className="space-y-2">
                                        <label className="text-sm font-semibold">Nom</label>
                                        <input
                                            type="text"
                                            value={data.name}
                                            onChange={e => setData('name', e.target.value)}
                                            className="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 outline-none focus:border-[#0D47A1] focus:ring-1 focus:ring-[#0D47A1] dark:border-slate-800 dark:bg-slate-900"
                                            placeholder="Votre nom"
                                            required
                                        />
                                        {errors.name && <p className="text-xs text-red-500">{errors.name}</p>}
                                    </div>
                                    <div className="space-y-2">
                                        <label className="text-sm font-semibold">Email</label>
                                        <input
                                            type="email"
                                            value={data.email}
                                            onChange={e => setData('email', e.target.value)}
                                            className="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 outline-none focus:border-[#0D47A1] focus:ring-1 focus:ring-[#0D47A1] dark:border-slate-800 dark:bg-slate-900"
                                            placeholder="votre@email.com"
                                            required
                                        />
                                        {errors.email && <p className="text-xs text-red-500">{errors.email}</p>}
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <label className="text-sm font-semibold">Sujet</label>
                                    <input
                                        type="text"
                                        value={data.subject}
                                        onChange={e => setData('subject', e.target.value)}
                                        className="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 outline-none focus:border-[#0D47A1] focus:ring-1 focus:ring-[#0D47A1] dark:border-slate-800 dark:bg-slate-900"
                                        placeholder="Comment pouvons-nous vous aider ?"
                                        required
                                    />
                                    {errors.subject && <p className="text-xs text-red-500">{errors.subject}</p>}
                                </div>

                                <div className="space-y-2">
                                    <label className="text-sm font-semibold">Message</label>
                                    <textarea
                                        rows={4}
                                        value={data.message}
                                        onChange={e => setData('message', e.target.value)}
                                        className="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 outline-none focus:border-[#0D47A1] focus:ring-1 focus:ring-[#0D47A1] dark:border-slate-800 dark:bg-slate-900"
                                        placeholder="Décrivez votre besoin..."
                                        required
                                    />
                                    {errors.message && <p className="text-xs text-red-500">{errors.message}</p>}
                                </div>

                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="w-full rounded-xl bg-[#0D47A1] py-4 font-bold text-white shadow-lg transition-all hover:bg-[#0a3a85] active:scale-95 disabled:opacity-50"
                                >
                                    {processing ? 'Envoi...' : 'Envoyer le message'}
                                </button>
                            </form>
                        )}
                    </div>
                </div>
            </main>
        </div>
    );
}
