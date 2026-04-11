import { Head, router, Link } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { BreadcrumbItem } from '@/types';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { FileText, Plus, Trash2, Edit, Eye, FileSearch } from 'lucide-react';
import { useState } from 'react';
import UploadJournalModal from './Components/UploadJournalModal';
import EditJournalModal from './Components/EditJournalModal';
import PdfViewerModal from './Components/PdfViewerModal';
import { toast } from 'sonner';
import { OfficialJournal } from './types';

interface PaginationData {
    data: OfficialJournal[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export default function Index({ journals }: { journals: PaginationData }) {
    const [isUploadModalOpen, setIsUploadModalOpen] = useState(false);
    const [isEditModalOpen, setIsEditModalOpen] = useState(false);
    const [isPdfModalOpen, setIsPdfModalOpen] = useState(false);
    const [journalToEdit, setJournalToEdit] = useState<OfficialJournal | null>(null);
    const [journalToView, setJournalToView] = useState<OfficialJournal | null>(null);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Journal Officiel', href: '/official-journals' },
    ];

    const getStatusColor = (status: string) => {
        switch (status) {
            case 'completed':
                return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300';
            case 'in_progress':
                return 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300';
            case 'failed':
                return 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300';
            default:
                return 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300';
        }
    };

    const getStatusLabel = (status: string) => {
        switch (status) {
            case 'completed':
                return 'Terminé';
            case 'in_progress':
                return 'En cours';
            case 'failed':
                return 'Échoué';
            case 'pending':
                return 'En attente';
            default:
                return status;
        }
    };

    const handleDelete = (id: string) => {
        if (confirm('Êtes-vous sûr de vouloir supprimer ce journal officiel ? Les documents juridiques associés perdront leur lien.')) {
            router.delete(`/official-journals/${id}`, {
                onSuccess: () => toast.success('Journal Officiel supprimé avec succès'),
            });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Journal Officiel" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Journal Officiel</h1>
                        <p className="text-muted-foreground">
                            Gérez les publications du Journal Officiel et leur retranscription.
                        </p>
                    </div>
                    <Button onClick={() => setIsUploadModalOpen(true)}>
                        <Plus className="mr-2 h-4 w-4" />
                        Nouveau J.O.
                    </Button>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Publications récentes</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Titre</TableHead>
                                    <TableHead>Date de publication</TableHead>
                                    <TableHead>Documents</TableHead>
                                    <TableHead>Statut transcription</TableHead>
                                    <TableHead>Publié</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {journals.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={6} className="h-24 text-center">
                                            Aucun journal officiel trouvé.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    journals.data.map((journal) => (
                                        <TableRow key={journal.id}>
                                            <TableCell className="font-medium">
                                                <div className="flex items-center gap-2">
                                                    <FileText className="h-4 w-4 text-muted-foreground" />
                                                    {journal.title}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                {journal.publication_date
                                                    ? new Date(journal.publication_date).toLocaleDateString('fr-FR')
                                                    : 'N/A'}
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant="outline">{journal.legal_documents_count}</Badge>
                                            </TableCell>
                                            <TableCell>
                                                <Badge className={getStatusColor(journal.transcription_status)} variant="secondary">
                                                    {getStatusLabel(journal.transcription_status)}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                {journal.is_published ? (
                                                    <Badge className="bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300">Oui</Badge>
                                                ) : (
                                                    <Badge variant="secondary">Non</Badge>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <div className="flex justify-end gap-2">
                                                    {journal.file_path && (
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            onClick={() => {
                                                                setJournalToView(journal);
                                                                setIsPdfModalOpen(true);
                                                            }}
                                                            title="Aperçu PDF"
                                                        >
                                                            <FileSearch className="h-4 w-4" />
                                                        </Button>
                                                    )}
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        asChild
                                                        title="Voir les détails et documents liés"
                                                    >
                                                        <Link href={`/official-journals/${journal.id}`}>
                                                            <Eye className="h-4 w-4" />
                                                        </Link>
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        onClick={() => {
                                                            setJournalToEdit(journal);
                                                            setIsEditModalOpen(true);
                                                        }}
                                                        title="Modifier"
                                                    >
                                                        <Edit className="h-4 w-4" />
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        onClick={() => handleDelete(journal.id)}
                                                        title="Supprimer"
                                                    >
                                                        <Trash2 className="h-4 w-4 text-red-500" />
                                                    </Button>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>

            <UploadJournalModal
                isOpen={isUploadModalOpen}
                onClose={() => setIsUploadModalOpen(false)}
            />

            <EditJournalModal
                isOpen={isEditModalOpen}
                onClose={() => {
                    setIsEditModalOpen(false);
                    setJournalToEdit(null);
                }}
                journal={journalToEdit}
            />

            <PdfViewerModal
                isOpen={isPdfModalOpen}
                onClose={() => {
                    setIsPdfModalOpen(false);
                    setJournalToView(null);
                }}
                pdfUrl={journalToView?.file_path ? `/pdf-proxy/${journalToView.id}?type=journal` : null}
                title={journalToView?.title || ''}
            />
        </AppLayout>
    );
}
