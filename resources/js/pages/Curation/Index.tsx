import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
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
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import { 
    Plus, 
    Search, 
    MoreHorizontal, 
    ExternalLink, 
    Trash2, 
    FileText, 
    AlertCircle,
    ChevronLeft,
    ChevronRight,
    Star
} from 'lucide-react';
import { useState, useEffect } from 'react';
import { cn } from '@/lib/utils';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Curation',
        href: '/curation',
    },
];

interface Document {
    id: string;
    title: string;
    type: string;
    type_code: string;
    institution: string;
    date: string | null;
    articles_count: number;
    status: 'draft' | 'review' | 'validated' | 'published';
    progression: number;
    quality_score: number;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    documents: {
        data: Document[];
        links: PaginationLink[];
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: {
        search?: string;
        type?: string;
        status?: string;
    };
    document_types: { code: string; nom: string }[];
    institutions: { id: string; nom: string }[];
}

const statusVariants: Record<string, "default" | "secondary" | "destructive" | "outline"> = {
    draft: 'secondary',
    review: 'outline',
    validated: 'default',
    published: 'default', // Using default but maybe custom class later
};

const statusLabels: Record<string, string> = {
    draft: 'Brouillon',
    review: 'À réviser',
    validated: 'Validé',
    published: 'Publié',
};

export default function CurationIndex({ documents, filters, document_types, institutions }: Props) {
    const [search, setSearch] = useState(filters.search || '');
    const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        titre_officiel: '',
        type_code: '',
        institution_id: institutions[0]?.id || '',
        reference_nor: '',
        date_publication: '',
        source_url: '',
        curation_status: 'draft',
    });

    const handleFilterChange = (key: string, value: string) => {
        const newFilters = { ...filters, [key]: value };
        if (value === 'all') delete (newFilters as any)[key];
        
        router.get('/curation', newFilters, {
            preserveState: true,
            replace: true,
        });
    };

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        handleFilterChange('search', search);
    };

    const handleDelete = (id: string) => {
        if (confirm('Êtes-vous sûr de vouloir supprimer ce document ? Cette action est irréversible.')) {
            router.delete(`/curation/${id}`);
        }
    };

    const submitCreate = (e: React.FormEvent) => {
        e.preventDefault();
        post('/curation', {
            onSuccess: () => {
                setIsCreateModalOpen(false);
                reset();
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Curation Dashboard" />
            
            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                {/* Header Section */}
                <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Gestion des Documents</h1>
                        <p className="text-muted-foreground">
                            Analysez, structurez et validez le corpus juridique.
                        </p>
                    </div>
                    
                    <Dialog open={isCreateModalOpen} onOpenChange={setIsCreateModalOpen}>
                        <DialogTrigger asChild>
                            <Button className="h-10">
                                <Plus className="mr-2 h-4 w-4" />
                                Ajouter un document
                            </Button>
                        </DialogTrigger>
                        <DialogContent className="sm:max-w-[600px]">
                            <form onSubmit={submitCreate}>
                                <DialogHeader>
                                    <DialogTitle>Nouveau Document Juridique</DialogTitle>
                                    <DialogDescription>
                                        Remplissez les informations de base pour commencer la curation.
                                    </DialogDescription>
                                </DialogHeader>
                                <div className="grid gap-4 py-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="titre_officiel">Titre Officiel</Label>
                                        <Input 
                                            id="titre_officiel" 
                                            value={data.titre_officiel}
                                            onChange={e => setData('titre_officiel', e.target.value)}
                                            placeholder="ex: Loi n°..."
                                            required
                                        />
                                        {errors.titre_officiel && <p className="text-xs text-destructive">{errors.titre_officiel}</p>}
                                    </div>
                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="grid gap-2">
                                            <Label htmlFor="type_code">Type de Document</Label>
                                            <Select value={data.type_code} onValueChange={val => setData('type_code', val)}>
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Sélectionner..." />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {document_types.map(t => (
                                                        <SelectItem key={t.code} value={t.code}>{t.nom}</SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            {errors.type_code && <p className="text-xs text-destructive">{errors.type_code}</p>}
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="institution_id">Institution</Label>
                                            <Select value={data.institution_id} onValueChange={val => setData('institution_id', val)}>
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Sélectionner..." />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {institutions.map(i => (
                                                        <SelectItem key={i.id} value={i.id}>{i.nom}</SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            {errors.institution_id && <p className="text-xs text-destructive">{errors.institution_id}</p>}
                                        </div>
                                    </div>
                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="grid gap-2">
                                            <Label htmlFor="reference_nor">Référence NOR</Label>
                                            <Input 
                                                id="reference_nor" 
                                                value={data.reference_nor}
                                                onChange={e => setData('reference_nor', e.target.value)}
                                                placeholder="ex: JUSX..."
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="date_publication">Date de Publication</Label>
                                            <Input 
                                                id="date_publication" 
                                                type="date"
                                                value={data.date_publication}
                                                onChange={e => setData('date_publication', e.target.value)}
                                            />
                                        </div>
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="source_url">URL Source / Chemin PDF</Label>
                                        <Input 
                                            id="source_url" 
                                            value={data.source_url}
                                            onChange={e => setData('source_url', e.target.value)}
                                            placeholder="https://... ou path/to/file.pdf"
                                        />
                                    </div>
                                </div>
                                <DialogFooter>
                                    <Button type="button" variant="outline" onClick={() => setIsCreateModalOpen(false)}>Annuler</Button>
                                    <Button type="submit" disabled={processing}>Créer le document</Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>

                {/* Filters Section */}
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center">
                    <form onSubmit={handleSearch} className="relative flex-1">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input 
                            placeholder="Rechercher par titre..." 
                            className="pl-10" 
                            value={search}
                            onChange={e => setSearch(e.target.value)}
                        />
                    </form>
                    <div className="flex flex-wrap items-center gap-3">
                        <Select value={filters.type || 'all'} onValueChange={v => handleFilterChange('type', v)}>
                            <SelectTrigger className="w-[180px]">
                                <SelectValue placeholder="Type" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Tous les types</SelectItem>
                                {document_types.map(t => (
                                    <SelectItem key={t.code} value={t.code}>{t.nom}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        
                        <Select value={filters.status || 'all'} onValueChange={v => handleFilterChange('status', v)}>
                            <SelectTrigger className="w-[180px]">
                                <SelectValue placeholder="Statut" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Tous les statuts</SelectItem>
                                <SelectItem value="draft">Brouillon</SelectItem>
                                <SelectItem value="review">À réviser</SelectItem>
                                <SelectItem value="validated">Validé</SelectItem>
                                <SelectItem value="published">Publié</SelectItem>
                            </SelectContent>
                        </Select>
                        
                        {(filters.search || filters.type || filters.status) && (
                            <Button variant="ghost" className="h-9 px-2" onClick={() => router.get('/curation')}>
                                Réinitialiser
                            </Button>
                        )}
                    </div>
                </div>

                {/* Table Section */}
                <Card className="overflow-hidden">
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow className="bg-muted/50">
                                    <TableHead className="w-[40%] font-semibold">Titre</TableHead>
                                    <TableHead className="font-semibold">Type</TableHead>
                                    <TableHead className="font-semibold">Qualité</TableHead>
                                    <TableHead className="font-semibold">Publication</TableHead>
                                    <TableHead className="font-semibold">Progression</TableHead>
                                    <TableHead className="font-semibold">Statut</TableHead>
                                    <TableHead className="text-right font-semibold">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {documents.data.length > 0 ? (
                                    documents.data.map((doc) => (
                                        <TableRow key={doc.id} className="group transition-colors hover:bg-muted/30">
                                            <TableCell className="font-medium">
                                                <div className="flex flex-col gap-1">
                                                    <span className="line-clamp-2 leading-tight" title={doc.title}>
                                                        {doc.title}
                                                    </span>
                                                    <span className="text-[10px] text-muted-foreground uppercase tracking-wider font-bold">
                                                        {doc.institution}
                                                    </span>
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-xs text-muted-foreground">
                                                {doc.type}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center gap-1.5">
                                                    <div className={cn(
                                                        "flex h-7 w-7 items-center justify-center rounded-full text-[10px] font-bold",
                                                        doc.quality_score >= 90 ? "bg-green-100 text-green-700" :
                                                        doc.quality_score >= 70 ? "bg-yellow-100 text-yellow-700" :
                                                        "bg-red-100 text-red-700"
                                                    )}>
                                                        {doc.quality_score}
                                                    </div>
                                                    <div className="flex flex-col">
                                                        <div className="flex gap-0.5">
                                                            {[1, 2, 3].map(i => (
                                                                <Star key={i} className={cn(
                                                                    "h-2.5 w-2.5",
                                                                    i <= (doc.quality_score / 33) ? "fill-yellow-400 text-yellow-400" : "text-gray-300"
                                                                )} />
                                                            ))}
                                                        </div>
                                                    </div>
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-xs">
                                                {doc.date ? new Date(doc.date).toLocaleDateString('fr-FR', {
                                                    day: '2-digit',
                                                    month: 'short',
                                                    year: 'numeric'
                                                }) : '--'}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex w-[100px] flex-col gap-1.5">
                                                    <div className="h-1.5 w-full overflow-hidden rounded-full bg-secondary">
                                                        <div 
                                                            className={cn(
                                                                "h-full transition-all duration-500",
                                                                doc.progression === 100 ? "bg-green-500" : "bg-primary"
                                                            )}
                                                            style={{ width: `${doc.progression}%` }}
                                                        />
                                                    </div>
                                                    <span className="text-[10px] text-muted-foreground">
                                                        {doc.progression}% - {doc.articles_count} art.
                                                    </span>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <Badge 
                                                    variant={statusVariants[doc.status]}
                                                    className={cn(
                                                        "px-2 py-0.5 text-[10px] uppercase font-bold",
                                                        doc.status === 'published' && "bg-blue-600 hover:bg-blue-700 text-white border-transparent"
                                                    )}
                                                >
                                                    {statusLabels[doc.status]}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <div className="flex items-center justify-end gap-2">
                                                    <Link href={`/curation/${doc.id}`}>
                                                        <Button size="icon" variant="ghost" className="h-8 w-8 text-muted-foreground hover:text-primary">
                                                            <ExternalLink className="h-4 w-4" />
                                                            <span className="sr-only">Ouvrir</span>
                                                        </Button>
                                                    </Link>
                                                    
                                                    <DropdownMenu>
                                                        <DropdownMenuTrigger asChild>
                                                            <Button size="icon" variant="ghost" className="h-8 w-8">
                                                                <MoreHorizontal className="h-4 w-4" />
                                                            </Button>
                                                        </DropdownMenuTrigger>
                                                        <DropdownMenuContent align="end">
                                                            <DropdownMenuItem onClick={() => router.get(`/curation/${doc.id}`)}>
                                                                Modifier la structure
                                                            </DropdownMenuItem>
                                                            <DropdownMenuItem 
                                                                className="text-destructive focus:bg-destructive/10 focus:text-destructive"
                                                                onClick={() => handleDelete(doc.id)}
                                                            >
                                                                <Trash2 className="mr-2 h-4 w-4" />
                                                                Supprimer
                                                            </DropdownMenuItem>
                                                        </DropdownMenuContent>
                                                    </DropdownMenu>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                ) : (
                                    <TableRow>
                                        <TableCell colSpan={7} className="h-64 text-center">
                                            <div className="flex flex-col items-center justify-center gap-2 text-muted-foreground">
                                                <AlertCircle className="h-10 w-10 opacity-20" />
                                                <p className="text-sm">Aucun document ne correspond à vos critères.</p>
                                                <Button variant="link" onClick={() => router.get('/curation')}>Effacer les filtres</Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {/* Pagination Section */}
                {documents.last_page > 1 && (
                    <div className="flex items-center justify-between px-2">
                        <p className="text-sm text-muted-foreground">
                            Page {documents.current_page} sur {documents.last_page}
                        </p>
                        <div className="flex items-center space-x-2">
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={!documents.prev_page_url}
                                onClick={() => documents.prev_page_url && router.get(documents.prev_page_url)}
                            >
                                <ChevronLeft className="mr-2 h-4 w-4" />
                                Précédent
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={!documents.next_page_url}
                                onClick={() => documents.next_page_url && router.get(documents.next_page_url)}
                            >
                                Suivant
                                <ChevronRight className="ml-2 h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
