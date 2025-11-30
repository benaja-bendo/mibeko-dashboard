import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    ArrowLeft,
    Building2,
    Calendar,
    ExternalLink,
    FileText,
    Hash,
} from 'lucide-react';

interface StructureNode {
    id: string;
    type_unite: string;
    numero: string | null;
    titre: string | null;
    tree_path: string;
}

interface ArticleVersion {
    id: string;
    contenu_texte: string;
    valid_from: string;
    valid_until: string | null;
    is_current: boolean;
}

interface Article {
    id: string;
    numero_article: string;
    ordre_affichage: number;
    parent_node?: {
        type_unite: string;
        numero: string | null;
        titre: string | null;
    };
    versions: ArticleVersion[];
}

interface LegalDocument {
    id: string;
    type: {
        code: string;
        nom: string;
        niveau_hierarchique: number;
    };
    institution: {
        nom: string;
        sigle: string | null;
    };
    titre_officiel: string;
    reference_nor: string | null;
    dates: {
        signature: string | null;
        publication: string | null;
        entree_vigueur: string | null;
    };
    source_url: string | null;
    statut: string;
    structure_nodes: StructureNode[];
    articles: Article[];
}

interface Props {
    document: LegalDocument;
}

const statusColors: Record<string, string> = {
    vigueur: 'bg-green-500',
    abroge: 'bg-red-500',
    projet: 'bg-yellow-500',
};

export default function DocumentsShow({ document }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Documents', href: '/documents' },
        { title: document.titre_officiel, href: `/documents/${document.id}` },
    ];

    // Group articles by structure node
    const articlesByNode = document.articles.reduce(
        (acc, article) => {
            const nodeId = article.parent_node?.titre || 'Sans structure';
            if (!acc[nodeId]) {
                acc[nodeId] = [];
            }
            acc[nodeId].push(article);
            return acc;
        },
        {} as Record<string, Article[]>,
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={document.titre_officiel} />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                {/* Header */}
                <div className="flex items-start justify-between">
                    <div className="space-y-2">
                        <Link href="/documents">
                            <Button variant="ghost" size="sm">
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                Retour
                            </Button>
                        </Link>
                        <div className="flex items-center gap-3">
                            <h1 className="text-3xl font-bold">
                                {document.titre_officiel}
                            </h1>
                            <div
                                className={`flex h-3 w-3 rounded-full ${statusColors[document.statut]}`}
                            />
                        </div>
                        <div className="flex items-center gap-2">
                            <Badge variant="outline">{document.type.nom}</Badge>
                            {document.reference_nor && (
                                <Badge variant="secondary">
                                    {document.reference_nor}
                                </Badge>
                            )}
                        </div>
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-3">
                    {/* Metadata Sidebar */}
                    <div className="space-y-4 lg:col-span-1">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Informations
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="flex items-start gap-3">
                                    <Building2 className="mt-0.5 h-4 w-4 text-muted-foreground" />
                                    <div className="flex-1 space-y-1">
                                        <p className="text-sm font-medium">
                                            Institution
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {document.institution.sigle ||
                                                document.institution.nom}
                                        </p>
                                    </div>
                                </div>

                                <Separator />

                                <div className="space-y-3">
                                    {document.dates.signature && (
                                        <div className="flex items-start gap-3">
                                            <Calendar className="mt-0.5 h-4 w-4 text-muted-foreground" />
                                            <div className="flex-1 space-y-1">
                                                <p className="text-sm font-medium">
                                                    Date de signature
                                                </p>
                                                <p className="text-sm text-muted-foreground">
                                                    {new Date(
                                                        document.dates.signature,
                                                    ).toLocaleDateString('fr-FR')}
                                                </p>
                                            </div>
                                        </div>
                                    )}

                                    {document.dates.publication && (
                                        <div className="flex items-start gap-3">
                                            <Calendar className="mt-0.5 h-4 w-4 text-muted-foreground" />
                                            <div className="flex-1 space-y-1">
                                                <p className="text-sm font-medium">
                                                    Date de publication
                                                </p>
                                                <p className="text-sm text-muted-foreground">
                                                    {new Date(
                                                        document.dates.publication,
                                                    ).toLocaleDateString('fr-FR')}
                                                </p>
                                            </div>
                                        </div>
                                    )}

                                    {document.dates.entree_vigueur && (
                                        <div className="flex items-start gap-3">
                                            <Calendar className="mt-0.5 h-4 w-4 text-muted-foreground" />
                                            <div className="flex-1 space-y-1">
                                                <p className="text-sm font-medium">
                                                    Entrée en vigueur
                                                </p>
                                                <p className="text-sm text-muted-foreground">
                                                    {new Date(
                                                        document.dates
                                                            .entree_vigueur,
                                                    ).toLocaleDateString('fr-FR')}
                                                </p>
                                            </div>
                                        </div>
                                    )}
                                </div>

                                {document.source_url && (
                                    <>
                                        <Separator />
                                        <a
                                            href={document.source_url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className="w-full"
                                            >
                                                <ExternalLink className="mr-2 h-4 w-4" />
                                                Source PDF
                                            </Button>
                                        </a>
                                    </>
                                )}
                            </CardContent>
                        </Card>

                        {/* Structure */}
                        {document.structure_nodes.length > 0 && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Structure
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-2">
                                        {document.structure_nodes.map((node) => (
                                            <div
                                                key={node.id}
                                                className="rounded-lg border p-3"
                                            >
                                                <p className="text-sm font-medium">
                                                    {node.type_unite}{' '}
                                                    {node.numero}
                                                </p>
                                                {node.titre && (
                                                    <p className="text-xs text-muted-foreground">
                                                        {node.titre}
                                                    </p>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        )}
                    </div>

                    {/* Main Content */}
                    <div className="lg:col-span-2">
                        <Tabs defaultValue="articles" className="space-y-4">
                            <TabsList>
                                <TabsTrigger value="articles">
                                    Articles ({document.articles.length})
                                </TabsTrigger>
                                <TabsTrigger value="structure">
                                    Structure complète
                                </TabsTrigger>
                            </TabsList>

                            <TabsContent value="articles" className="space-y-4">
                                {Object.entries(articlesByNode).map(
                                    ([nodeTitle, articles]) => (
                                        <Card key={nodeTitle}>
                                            <CardHeader>
                                                <CardTitle className="text-base">
                                                    {nodeTitle}
                                                </CardTitle>
                                            </CardHeader>
                                            <CardContent className="space-y-4">
                                                {articles.map((article) => {
                                                    const currentVersion =
                                                        article.versions.find(
                                                            (v) => v.is_current,
                                                        );
                                                    return (
                                                        <div
                                                            key={article.id}
                                                            className="space-y-2 rounded-lg border p-4"
                                                        >
                                                            <div className="flex items-center gap-2">
                                                                <Hash className="h-4 w-4 text-muted-foreground" />
                                                                <p className="font-semibold">
                                                                    Article{' '}
                                                                    {
                                                                        article.numero_article
                                                                    }
                                                                </p>
                                                                {article.versions
                                                                    .length >
                                                                    1 && (
                                                                    <Badge
                                                                        variant="secondary"
                                                                        className="text-xs"
                                                                    >
                                                                        {
                                                                            article
                                                                                .versions
                                                                                .length
                                                                        }{' '}
                                                                        versions
                                                                    </Badge>
                                                                )}
                                                            </div>
                                                            {currentVersion && (
                                                                <p className="text-sm leading-relaxed text-muted-foreground">
                                                                    {
                                                                        currentVersion.contenu_texte
                                                                    }
                                                                </p>
                                                            )}
                                                        </div>
                                                    );
                                                })}
                                            </CardContent>
                                        </Card>
                                    ),
                                )}

                                {document.articles.length === 0 && (
                                    <Card>
                                        <CardContent className="flex h-32 items-center justify-center">
                                            <div className="text-center">
                                                <FileText className="mx-auto h-8 w-8 text-muted-foreground" />
                                                <p className="mt-2 text-sm text-muted-foreground">
                                                    Aucun article disponible
                                                </p>
                                            </div>
                                        </CardContent>
                                    </Card>
                                )}
                            </TabsContent>

                            <TabsContent value="structure">
                                <Card>
                                    <CardContent className="p-6">
                                        <div className="space-y-3">
                                            {document.structure_nodes.map(
                                                (node) => (
                                                    <div
                                                        key={node.id}
                                                        className="space-y-2 rounded-lg border p-4"
                                                    >
                                                        <div className="flex items-center gap-2">
                                                            <Badge variant="outline">
                                                                {node.type_unite}
                                                            </Badge>
                                                            <p className="font-medium">
                                                                {node.numero}
                                                            </p>
                                                        </div>
                                                        {node.titre && (
                                                            <p className="text-sm text-muted-foreground">
                                                                {node.titre}
                                                            </p>
                                                        )}
                                                        <p className="text-xs font-mono text-muted-foreground">
                                                            {node.tree_path}
                                                        </p>
                                                    </div>
                                                ),
                                            )}
                                        </div>
                                    </CardContent>
                                </Card>
                            </TabsContent>
                        </Tabs>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
