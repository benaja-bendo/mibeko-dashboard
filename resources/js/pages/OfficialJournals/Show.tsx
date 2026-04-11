import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { BreadcrumbItem } from '@/types';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Trash2, Link as LinkIcon, ExternalLink, Calendar, Info, FileText } from 'lucide-react';
import { useState } from 'react';
import { OfficialJournal, AvailableDocument } from './types';
import { toast } from 'sonner';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Label } from '@/components/ui/label';
import PdfViewerModal from './Components/PdfViewerModal';

interface ShowProps {
    journal: OfficialJournal;
    availableDocuments: AvailableDocument[];
}

export default function Show({ journal, availableDocuments }: ShowProps) {
    const [isAttachModalOpen, setIsAttachModalOpen] = useState(false);
    const [selectedDocId, setSelectedDocId] = useState<string>('');
    const [isAttaching, setIsAttaching] = useState(false);
    const [isPdfModalOpen, setIsPdfModalOpen] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Journal Officiel', href: '/official-journals' },
        { title: journal.title, href: `/official-journals/${journal.id}` },
    ];

    const handleAttach = () => {
        if (!selectedDocId) return;

        setIsAttaching(true);
        router.post(`/official-journals/${journal.id}/attach`, {
            legal_document_id: selectedDocId
        }, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Document rattaché avec succès');
                setIsAttachModalOpen(false);
                setSelectedDocId('');
            },
            onFinish: () => setIsAttaching(false),
        });
    };

    const handleDetach = (documentId: string) => {
        if (confirm('Êtes-vous sûr de vouloir détacher ce document du Journal Officiel ?')) {
            router.delete(`/official-journals/${journal.id}/detach/${documentId}`, {
                preserveScroll: true,
                onSuccess: () => toast.success('Document détaché avec succès'),
            });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`J.O. - ${journal.title}`} />

            <div className="flex h-full flex-1 flex-col gap-4 p-4 md:p-6 lg:flex-row">
                {/* Colonne de gauche: Infos du J.O. et Documents attachés */}
                <div className="flex w-full flex-col gap-4 lg:w-1/3">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Info className="h-5 w-5" />
                                Informations
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <p className="text-sm font-medium text-muted-foreground">Titre</p>
                                <p className="text-base">{journal.title}</p>
                            </div>
                            <div>
                                <p className="text-sm font-medium text-muted-foreground">Date de publication</p>
                                <p className="flex items-center gap-2 text-base">
                                    <Calendar className="h-4 w-4" />
                                    {journal.publication_date 
                                        ? new Date(journal.publication_date).toLocaleDateString('fr-FR') 
                                        : 'Non spécifiée'}
                                </p>
                            </div>
                            <div className="flex gap-4">
                                <div>
                                    <p className="text-sm font-medium text-muted-foreground">Statut transcription</p>
                                    <Badge variant="secondary" className="mt-1">
                                        {journal.transcription_status}
                                    </Badge>
                                </div>
                                <div>
                                    <p className="text-sm font-medium text-muted-foreground">Publié</p>
                                    <Badge variant={journal.is_published ? 'default' : 'secondary'} className="mt-1">
                                        {journal.is_published ? 'Oui' : 'Non'}
                                    </Badge>
                                </div>
                            </div>
                            {journal.file_path && (
                                <div className="pt-2 flex flex-col gap-2">
                                    <Button variant="outline" className="w-full" onClick={() => setIsPdfModalOpen(true)}>
                                        <FileText className="mr-2 h-4 w-4" />
                                        Ouvrir le PDF en plein écran
                                    </Button>
                                    <Button variant="secondary" className="w-full" asChild>
                                        <a href={`/pdf-proxy/${journal.id}?type=journal`} target="_blank" rel="noopener noreferrer">
                                            <ExternalLink className="mr-2 h-4 w-4" />
                                            Ouvrir le PDF dans un nouvel onglet
                                        </a>
                                    </Button>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card className="flex-1">
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-lg">Documents Juridiques liés</CardTitle>
                            <Button size="sm" onClick={() => setIsAttachModalOpen(true)}>
                                <LinkIcon className="mr-2 h-4 w-4" />
                                Lier un document
                            </Button>
                        </CardHeader>
                        <CardContent>
                            <div className="rounded-md border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Titre / Réf.</TableHead>
                                            <TableHead className="w-[50px] text-right"></TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {(!journal.legal_documents || journal.legal_documents.length === 0) ? (
                                            <TableRow>
                                                <TableCell colSpan={2} className="text-center text-muted-foreground h-24">
                                                    Aucun document lié.
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            journal.legal_documents.map((doc) => (
                                                <TableRow key={doc.id}>
                                                    <TableCell>
                                                        <Link href={`/curation/${doc.id}`} className="font-medium hover:underline flex items-start gap-2">
                                                            <FileText className="h-4 w-4 shrink-0 mt-0.5" />
                                                            <div className="line-clamp-2">
                                                                {doc.titre_officiel}
                                                                {doc.reference_nor && (
                                                                    <span className="block text-xs text-muted-foreground font-normal">
                                                                        Réf: {doc.reference_nor}
                                                                    </span>
                                                                )}
                                                            </div>
                                                        </Link>
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <Button 
                                                            variant="ghost" 
                                                            size="icon" 
                                                            onClick={() => handleDetach(doc.id)}
                                                            title="Détacher"
                                                        >
                                                            <Trash2 className="h-4 w-4 text-red-500" />
                                                        </Button>
                                                    </TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Colonne de droite: Visualisation du PDF */}
                <div className="flex h-[600px] w-full flex-col lg:h-auto lg:w-2/3">
                    <Card className="flex h-full flex-col overflow-hidden">
                        <div className="flex h-12 items-center border-b px-4 shrink-0 bg-muted/50">
                            <h3 className="font-semibold flex items-center gap-2">
                                <FileText className="h-4 w-4" />
                                Visualisation du PDF
                            </h3>
                        </div>
                        <div className="flex-1 bg-zinc-900/5 dark:bg-black/50 relative">
                            {journal.file_path ? (
                                <iframe
                                    src={`/pdf-proxy/${journal.id}?type=journal`}
                                    className="h-full w-full border-0"
                                    title="PDF Viewer"
                                />
                            ) : (
                                <div className="flex h-full items-center justify-center text-muted-foreground">
                                    Aucun fichier PDF associé à ce journal officiel.
                                </div>
                            )}
                        </div>
                    </Card>
                </div>
            </div>

            <PdfViewerModal
                isOpen={isPdfModalOpen}
                onClose={() => setIsPdfModalOpen(false)}
                pdfUrl={journal.file_path ? `/pdf-proxy/${journal.id}?type=journal` : null}
                title={journal.title}
            />

            {/* Modal d'attachement */}
            <Dialog open={isAttachModalOpen} onOpenChange={setIsAttachModalOpen}>
                <DialogContent className="sm:max-w-[500px]">
                    <DialogHeader>
                        <DialogTitle>Rattacher un document juridique</DialogTitle>
                        <DialogDescription>
                            Sélectionnez un document existant pour le lier à ce Journal Officiel.
                        </DialogDescription>
                    </DialogHeader>
                    
                    <div className="py-4">
                        <Label className="mb-2 block">Document disponible</Label>
                        <Select value={selectedDocId} onValueChange={setSelectedDocId}>
                            <SelectTrigger>
                                <SelectValue placeholder="Sélectionner un document..." />
                            </SelectTrigger>
                            <SelectContent>
                                {availableDocuments.length === 0 ? (
                                    <div className="p-2 text-sm text-muted-foreground text-center">
                                        Aucun document non rattaché trouvé.
                                    </div>
                                ) : (
                                    availableDocuments.map((doc) => (
                                        <SelectItem key={doc.id} value={doc.id} className="cursor-pointer">
                                            {doc.titre_officiel.length > 60 
                                                ? `${doc.titre_officiel.substring(0, 60)}...` 
                                                : doc.titre_officiel}
                                        </SelectItem>
                                    ))
                                )}
                            </SelectContent>
                        </Select>
                        <p className="mt-2 text-xs text-muted-foreground">
                            Note: Seuls les documents n'ayant pas encore de Journal Officiel rattaché (ou les 50 derniers) sont affichés ici.
                        </p>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setIsAttachModalOpen(false)}>Annuler</Button>
                        <Button onClick={handleAttach} disabled={!selectedDocId || isAttaching}>
                            {isAttaching ? 'Rattachement...' : 'Rattacher'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

        </AppLayout>
    );
}