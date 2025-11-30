import { PlaceholderPattern } from '@/components/ui/placeholder-pattern';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FileText, Hash, ArrowRight } from 'lucide-react';
import { Button } from '@/components/ui/button';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
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
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">Dashboard</h1>
                    <p className="text-muted-foreground">
                        Bienvenue sur votre tableau de bord
                    </p>
                </div>

                {/* Statistics */}
                <div className="grid gap-4 md:grid-cols-3">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">
                                Documents juridiques
                            </CardTitle>
                            <FileText className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {stats.total_documents}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Total de documents
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">
                                Articles
                            </CardTitle>
                            <Hash className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {stats.total_articles}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Total d'articles
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">
                                  Moyenne
                            </CardTitle>
                            <Hash className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {stats.total_documents > 0
                                    ? Math.round(
                                          stats.total_articles /
                                              stats.total_documents,
                                      )
                                    : 0}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Articles par document
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {/* Recent Documents */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <CardTitle>Documents récents</CardTitle>
                            <Link href="/documents">
                                <Button variant="ghost" size="sm">
                                    Voir tout
                                    <ArrowRight className="ml-2 h-4 w-4" />
                                </Button>
                            </Link>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {stats.recent_documents.length > 0 ? (
                            <div className="space-y-3">
                                {stats.recent_documents.map((doc) => (
                                    <Link
                                        key={doc.id}
                                        href={`/documents/${doc.id}`}
                                        className="block"
                                    >
                                        <div className="flex items-center justify-between rounded-lg border p-3 transition-colors hover:bg-muted/50">
                                            <div className="flex-1 space-y-1">
                                                <p className="font-medium leading-none">
                                                    {doc.titre}
                                                </p>
                                                <p className="text-sm text-muted-foreground">
                                                    {doc.type}
                                                    {doc.date &&
                                                        ` • ${new Date(doc.date).toLocaleDateString('fr-FR')}`}
                                                </p>
                                            </div>
                                            <ArrowRight className="h-4 w-4 text-muted-foreground" />
                                        </div>
                                    </Link>
                                ))}
                            </div>
                        ) : (
                            <div className="flex h-32 items-center justify-center">
                                <div className="text-center">
                                    <FileText className="mx-auto h-8 w-8 text-muted-foreground" />
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        Aucun document disponible
                                    </p>
                                    <Link href="/documents">
                                        <Button variant="link" size="sm" className="mt-2">
                                            Commencer
                                        </Button>
                                    </Link>
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Quick Actions */}
                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Actions rapides</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            <Link href="/documents">
                                <Button variant="outline" className="w-full justify-start">
                                    <FileText className="mr-2 h-4 w-4" />
                                    Parcourir les documents
                                </Button>
                            </Link>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
