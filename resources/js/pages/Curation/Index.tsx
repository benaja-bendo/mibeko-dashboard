import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Edit, FileText, CheckCircle, Clock, AlertCircle } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Curation Dashboard',
        href: '/curation',
    },
];

interface Document {
    id: string;
    title: string;
    type: string;
    date: string | null;
    articles_count: number;
    status: 'draft' | 'review' | 'validated' | 'published';
}

interface Props {
    documents: {
        data: Document[];
        links: any[]; // Pagination links
    };
}

const statusColors = {
    draft: 'bg-gray-500',
    review: 'bg-yellow-500',
    validated: 'bg-green-500',
    published: 'bg-blue-500',
};

const statusLabels = {
    draft: 'Brouillon',
    review: 'En revue',
    validated: 'Validé',
    published: 'Publié',
};

export default function CurationIndex({ documents }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Curation Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Curation</h1>
                        <p className="text-muted-foreground">
                            Gérez et validez les textes juridiques importés.
                        </p>
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Documents à traiter</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Titre</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Date</TableHead>
                                    <TableHead>Articles</TableHead>
                                    <TableHead>Statut</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {documents.data.map((doc) => (
                                    <TableRow key={doc.id}>
                                        <TableCell className="font-medium">
                                            <div className="flex items-center gap-2">
                                                <FileText className="h-4 w-4 text-muted-foreground" />
                                                <span className="truncate max-w-[300px]" title={doc.title}>
                                                    {doc.title}
                                                </span>
                                            </div>
                                        </TableCell>
                                        <TableCell>{doc.type}</TableCell>
                                        <TableCell>{doc.date}</TableCell>
                                        <TableCell>{doc.articles_count}</TableCell>
                                        <TableCell>
                                            <Badge className={statusColors[doc.status] || 'bg-gray-500'}>
                                                {statusLabels[doc.status] || doc.status}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Link href={`/curation/${doc.id}`}>
                                                <Button size="sm" variant="outline">
                                                    <Edit className="mr-2 h-4 w-4" />
                                                    Ouvrir
                                                </Button>
                                            </Link>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {documents.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-center py-8 text-muted-foreground">
                                            Aucun document trouvé.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
