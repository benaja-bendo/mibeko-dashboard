import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Search, FileText, Eye } from 'lucide-react';

interface DocumentType {
    code: string;
    nom: string;
    niveau_hierarchique: number;
}

interface Institution {
    id: string;
    nom: string;
    sigle: string | null;
}

interface LegalDocument {
    id: string;
    type: {
        code: string;
        nom: string;
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
    statut: string;
}

interface Props {
    documents: {
        data: LegalDocument[];
        links: any[];
        meta: any;
    };
    filters: {
        search?: string;
        type?: string;
        institution?: string;
        status?: string;
    };
    types: DocumentType[];
    institutions: Institution[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Documents', href: '/documents' },
];

const statusColors: Record<string, string> = {
    vigueur: 'bg-green-500',
    abroge: 'bg-red-500',
    projet: 'bg-yellow-500',
};

export default function DocumentsIndex({
    documents,
    filters,
    types,
    institutions,
}: Props) {
    const [search, setSearch] = useState(filters.search || '');
    const [typeFilter, setTypeFilter] = useState(filters.type || '');
    const [institutionFilter, setInstitutionFilter] = useState(
        filters.institution || '',
    );
    const [statusFilter, setStatusFilter] = useState(filters.status || '');

    const handleFilter = () => {
        router.get(
            '/documents',
            {
                search: search || undefined,
                type: typeFilter || undefined,
                institution: institutionFilter || undefined,
                status: statusFilter || undefined,
            },
            { preserveState: true },
        );
    };

    const handleReset = () => {
        setSearch('');
        setTypeFilter('');
        setInstitutionFilter('');
        setStatusFilter('');
        router.get('/documents');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Documents juridiques" />
            
            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">
                            Documents juridiques
                        </h1>
                        <p className="text-muted-foreground">
                            Gérez et consultez vos textes officiels
                        </p>
                    </div>
                </div>

                {/* Filters */}
                <div className="grid gap-4 md:grid-cols-5">
                    <div className="relative md:col-span-2">
                        <Search className="absolute left-3 top-3 h-4 w-4 text-muted-foreground" />
                        <Input
                            placeholder="Rechercher par titre ou référence..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && handleFilter()}
                            className="pl-9"
                        />
                    </div>
                    
                    <Select value={typeFilter} onValueChange={setTypeFilter}>
                        <SelectTrigger>
                            <SelectValue placeholder="Type de document" />
                        </SelectTrigger>
                        <SelectContent>
                            {types.map((type) => (
                                <SelectItem key={type.code} value={type.code}>
                                    {type.nom}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select
                        value={institutionFilter}
                        onValueChange={setInstitutionFilter}
                    >
                        <SelectTrigger>
                            <SelectValue placeholder="Institution" />
                        </SelectTrigger>
                        <SelectContent>
                            {institutions.map((inst) => (
                                <SelectItem key={inst.id} value={inst.id}>
                                    {inst.sigle || inst.nom}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <div className="flex gap-2">
                        <Button onClick={handleFilter} size="sm" className="flex-1">
                            Filtrer
                        </Button>
                        <Button
                            onClick={handleReset}
                            size="sm"
                            variant="outline"
                            className="flex-1"
                        >
                            Réinitialiser
                        </Button>
                    </div>
                </div>

                {/* Documents Table */}
                <div className="rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Type</TableHead>
                                <TableHead>Titre</TableHead>
                                <TableHead>Institution</TableHead>
                                <TableHead>Date de publication</TableHead>
                                <TableHead>Statut</TableHead>
                                <TableHead className="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {documents.data.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={6}
                                        className="h-24 text-center"
                                    >
                                        <div className="flex flex-col items-center gap-2">
                                            <FileText className="h-8 w-8 text-muted-foreground" />
                                            <p className="text-muted-foreground">
                                                Aucun document trouvé
                                            </p>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ) : (
                                documents.data.map((doc) => (
                                    <TableRow key={doc.id}>
                                        <TableCell>
                                            <Badge variant="outline">
                                                {doc.type.nom}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="font-medium">
                                            {doc.titre_officiel}
                                            {doc.reference_nor && (
                                                <p className="text-xs text-muted-foreground">
                                                    {doc.reference_nor}
                                                </p>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {doc.institution.sigle || doc.institution.nom}
                                        </TableCell>
                                        <TableCell>
                                            {doc.dates.publication
                                                ? new Date(
                                                      doc.dates.publication,
                                                  ).toLocaleDateString('fr-FR')
                                                : '-'}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-2">
                                                <div
                                                    className={`h-2 w-2 rounded-full ${statusColors[doc.statut]}`}
                                                />
                                                <span className="capitalize">
                                                    {doc.statut}
                                                </span>
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Link href={`/documents/${doc.id}`}>
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                >
                                                    <Eye className="h-4 w-4" />
                                                </Button>
                                            </Link>
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>

                {/* Pagination */}
                {documents.links.length > 3 && (
                    <div className="flex items-center justify-center gap-2">
                        {documents.links.map((link, index) => (
                            <Button
                                key={index}
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url)}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
