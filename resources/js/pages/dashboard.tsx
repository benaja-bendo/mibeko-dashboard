import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FileText, Hash, ArrowRight, Plus, Users, ShieldCheck, History as HistoryIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Tableau de bord',
        href: dashboard().url,
    },
];

interface RecentDocument {
    id: string;
    titre: string;
    type: string;
    date: string | null;
}

interface Props {
    stats: {
        total_documents: number;
        total_articles: number;
        recent_documents: RecentDocument[];
    };
}

export default function Dashboard({ stats }: Props) {
    const { auth } = usePage<SharedData>().props;
    const roles = (auth.user?.roles as string[]) || [];
    const isAdmin = roles.includes('admin') || roles.includes('editor');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tableau de bord" />
            <div className="flex flex-1 flex-col gap-8 p-8 max-w-7xl mx-auto w-full">
                <div className="flex flex-col gap-2">
                    <h1 className="text-4xl font-extrabold tracking-tight text-primary">Tableau de bord</h1>
                    <p className="text-lg text-muted-foreground italic">
                        "Prendre connaissance de la loi, c'est commencer à être libre."
                    </p>
                </div>

                <div className="grid gap-6 md:grid-cols-3">
                    <Card className="border-none shadow-md bg-primary/5">
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium uppercase tracking-wider text-muted-foreground">
                                Documents
                            </CardTitle>
                            <FileText className="h-5 w-5 text-primary" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-3xl font-bold">{stats.total_documents}</div>
                            <p className="text-xs text-muted-foreground mt-1">Codes, Lois & Décrets</p>
                        </CardContent>
                    </Card>

                    <Card className="border-none shadow-md bg-primary/5">
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium uppercase tracking-wider text-muted-foreground">
                                Articles
                            </CardTitle>
                            <Hash className="h-5 w-5 text-primary" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-3xl font-bold">{stats.total_articles}</div>
                            <p className="text-xs text-muted-foreground mt-1">Base de données juridique</p>
                        </CardContent>
                    </Card>

                    <Card className="border-none shadow-md bg-primary/5">
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium uppercase tracking-wider text-muted-foreground">
                                Role
                            </CardTitle>
                            <ShieldCheck className="h-5 w-5 text-primary" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-xl font-bold capitalize">{roles[0] || 'Utilisateur'}</div>
                            <p className="text-xs text-muted-foreground mt-1">Niveau d'accès actuel</p>
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-8 lg:grid-cols-2">
                    <Card className="border-none shadow-sm h-full">
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-xl">Activités Récentes</CardTitle>
                                {isAdmin && (
                                    <Link href="/curation">
                                        <Button variant="ghost" size="sm" className="hover:bg-primary/10">
                                            Voir tout
                                            <ArrowRight className="ml-2 h-4 w-4" />
                                        </Button>
                                    </Link>
                                )}
                            </div>
                        </CardHeader>
                        <CardContent>
                            {stats.recent_documents.length > 0 ? (
                                <div className="space-y-4">
                                    {stats.recent_documents.map((doc) => (
                                        <div
                                            key={doc.id}
                                            className="group flex items-center justify-between rounded-xl border p-4 transition-all hover:shadow-md hover:border-primary/20"
                                        >
                                            <div className="flex-1 space-y-1">
                                                <p className="font-semibold text-foreground group-hover:text-primary transition-colors">
                                                    {doc.titre}
                                                </p>
                                                <p className="text-sm text-muted-foreground">
                                                    {doc.type}
                                                    {doc.date && ` • ${new Date(doc.date).toLocaleDateString('fr-FR')}`}
                                                </p>
                                            </div>
                                            {isAdmin && (
                                                <Link href={`/curation/${doc.id}`}>
                                                    <Button variant="ghost" size="icon" className="group-hover:translate-x-1 transition-transform">
                                                        <ArrowRight className="h-4 w-4" />
                                                    </Button>
                                                </Link>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="flex h-48 items-center justify-center rounded-xl border border-dashed">
                                    <div className="text-center text-muted-foreground">
                                        <FileText className="mx-auto h-10 w-10 opacity-20" />
                                        <p className="mt-2">Aucune activité récente</p>
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <div className="flex flex-col gap-6">
                        <Card className="border-none shadow-sm">
                            <CardHeader>
                                <CardTitle className="text-xl">Actions Rapides</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-3">
                                {isAdmin ? (
                                    <>
                                        <Link href="/curation">
                                            <Button className="w-full justify-start h-12 text-base" size="lg">
                                                <Plus className="mr-3 h-5 w-5" />
                                                Ajouter un document
                                            </Button>
                                        </Link>
                                        <Link href="/auditing">
                                            <Button variant="outline" className="w-full justify-start h-12 text-base" size="lg">
                                                <HistoryIcon className="mr-3 h-5 w-5" />
                                                Consulter l'historique
                                            </Button>
                                        </Link>
                                    </>
                                ) : (
                                    <div className="p-4 bg-muted/30 rounded-lg text-sm text-muted-foreground">
                                        <p>En tant qu'utilisateur standard, vous pouvez consulter les documents via l'application mobile Mibeko.</p>
                                        <p className="mt-2">Contactez un administrateur pour obtenir des droits d'édition sur le portail web.</p>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        <Card className="border-none shadow-sm">
                            <CardHeader>
                                <CardTitle className="text-xl">Support</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="flex gap-4">
                                    <Button variant="secondary" className="flex-1">Support technique</Button>
                                    <Button variant="secondary" className="flex-1">Documentation</Button>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
