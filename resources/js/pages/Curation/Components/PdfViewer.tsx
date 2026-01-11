import { useState, useEffect } from 'react';
import { router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import {
    PanelRightClose,
    Link2,
    FileWarning,
    Maximize2,
    Download,
    Search,
    RefreshCcw,
    FileIcon
} from 'lucide-react';
import { toast } from 'sonner';

// Helper function to build the PDF proxy URL with a cache buster
const getPdfProxyUrl = (documentId: string, sourceUrl: string | null): string => {
    if (!sourceUrl) return '';
    // We add the sourceUrl path hash or a timestamp to force iframe reload when the file changes
    const hash = btoa(sourceUrl).substring(0, 8);
    return `/pdf-proxy/${documentId}?v=${hash}`;
};

interface Document {
    id: string;
    title: string;
    source_url: string | null;
    status: string;
}

interface MinioFile {
    path: string;
    name: string;
    size: number;
    last_modified: number;
}

interface PdfViewerProps {
    document: Document;
    collapsed: boolean;
    onToggle: () => void;
}

export default function PdfViewer({ document, onToggle }: Omit<PdfViewerProps, 'collapsed'>) {
    const [isEditDialogOpen, setIsEditDialogOpen] = useState(false);
    const [isBrowseDialogOpen, setIsBrowseDialogOpen] = useState(false);
    const [urlInput, setUrlInput] = useState(document.source_url || '');
    const [isSubmitting, setIsSubmitting] = useState(false);

    // Key to force iframe re-mount
    const [iframeKey, setIframeKey] = useState(0);

    // Minio files state
    const [availableFiles, setAvailableFiles] = useState<MinioFile[]>([]);
    const [isLoadingFiles, setIsLoadingFiles] = useState(false);
    const [searchQuery, setSearchQuery] = useState('');

    // Synchronize urlInput and force reload when document.source_url changes
    useEffect(() => {
        setUrlInput(document.source_url || '');
        setIframeKey(prev => prev + 1);
    }, [document.source_url, document.id]);

    const handleSaveUrl = () => {
        setIsSubmitting(true);
        router.patch(`/curation/${document.id}/source-url`, {
            source_url: urlInput || null
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setIsEditDialogOpen(false);
                toast.success('Source mise à jour');
            },
            onFinish: () => setIsSubmitting(false),
        });
    };

    const fetchFiles = async () => {
        setIsLoadingFiles(true);
        try {
            const response = await fetch('/api/media/files');
            const data = await response.json();
            setAvailableFiles(data.files);
        } catch {
            toast.error('Erreur lors de la récupération des fichiers');
        } finally {
            setIsLoadingFiles(false);
        }
    };

    const handleAttachFile = (filePath: string) => {
        setIsSubmitting(true);
        router.post(`/curation/${document.id}/attach-media`, {
            file_path: filePath
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setIsBrowseDialogOpen(false);
                toast.success('Fichier attaché avec succès');
            },
            onFinish: () => setIsSubmitting(false),
        });
    };

    const filteredFiles = availableFiles.filter(file =>
        file.name.toLowerCase().includes(searchQuery.toLowerCase())
    );

    const formatSize = (bytes: number) => {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    };

    return (
        <div className="h-full flex flex-col bg-zinc-900">
            {/* Header */}
            <div className="flex items-center justify-between h-12 px-4 border-b border-zinc-700 bg-zinc-800 shrink-0">
                <div className="flex items-center gap-2 overflow-hidden">
                    <Button variant="ghost" size="icon" onClick={onToggle} className="h-8 w-8 text-zinc-400 hover:bg-zinc-700" title="Masquer le PDF">
                        <PanelRightClose className="h-4 w-4" />
                    </Button>
                    <div className="text-zinc-400 text-xs truncate font-mono">
                        {document.source_url ? (
                            <span title={document.source_url}>{document.source_url.split('/').pop()}</span>
                        ) : (
                            "Aucune source"
                        )}
                    </div>
                </div>
                <div className="flex items-center gap-1">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                            setIsBrowseDialogOpen(true);
                            fetchFiles();
                        }}
                        className="h-8 px-2 text-zinc-400 hover:text-zinc-100 hover:bg-zinc-700 gap-1.5"
                    >
                         <Search className="h-3.5 w-3.5" />
                         <span className="text-xs">Parcourir</span>
                    </Button>
                    <Button variant="ghost" size="sm" onClick={() => setIsEditDialogOpen(true)} className="h-8 w-8 p-0 text-zinc-400 hover:text-zinc-100 hover:bg-zinc-700">
                         <Link2 className="h-4 w-4" />
                    </Button>
                    {document.source_url && (
                        <a
                            href={`${getPdfProxyUrl(document.id, document.source_url)}&download=1`}
                            className="inline-flex items-center justify-center h-8 w-8 rounded-md text-zinc-400 hover:text-zinc-100 hover:bg-zinc-700"
                            title="Télécharger le PDF"
                        >
                            <Download className="h-4 w-4" />
                        </a>
                    )}
                    {document.source_url && (
                        <a
                            href={getPdfProxyUrl(document.id, document.source_url)}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex items-center justify-center h-8 w-8 rounded-md text-zinc-400 hover:text-zinc-100 hover:bg-zinc-700"
                        >
                            <Maximize2 className="h-4 w-4" />
                        </a>
                    )}
                </div>
            </div>

            {/* Content */}
            <div className="flex-1 relative bg-black/50 overflow-hidden">
                {document.source_url ? (
                    <iframe
                        key={iframeKey}
                        src={getPdfProxyUrl(document.id, document.source_url)}
                        className="h-full w-full border-0"
                        title="PDF Viewer"
                    />
                ) : (
                    <div className="flex flex-col items-center justify-center h-full text-zinc-500 p-4 text-center">
                        <FileWarning className="mb-2 h-8 w-8 opacity-50" />
                        <span className="text-sm">Pas de document source</span>
                        <div className="flex gap-2 mt-4">
                            <Button variant="outline" size="sm" onClick={() => { setIsBrowseDialogOpen(true); fetchFiles(); }} className="text-zinc-300 border-zinc-700 hover:bg-zinc-800">
                                Parcourir MinIO
                            </Button>
                            <Button variant="link" size="sm" onClick={() => setIsEditDialogOpen(true)} className="text-blue-400">
                                Lien manuel
                            </Button>
                        </div>
                    </div>
                )}
            </div>

            {/* Edit URL Dialog */}
            <Dialog open={isEditDialogOpen} onOpenChange={setIsEditDialogOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Modifier la source du document</DialogTitle>
                    </DialogHeader>
                    <div className="py-2">
                        <Input
                            value={urlInput}
                            onChange={e => setUrlInput(e.target.value)}
                            placeholder="Chemin Minio (ex: sources/file.pdf)..."
                        />
                         <p className="text-xs text-muted-foreground mt-2">
                            Entrez le chemin relatif dans le bucket de stockage.
                        </p>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setIsEditDialogOpen(false)}>Annuler</Button>
                        <Button onClick={handleSaveUrl} disabled={isSubmitting}>Enregistrer</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Browse MinIO Dialog */}
            <Dialog open={isBrowseDialogOpen} onOpenChange={setIsBrowseDialogOpen}>
                <DialogContent className="sm:max-w-3xl max-h-[80vh] flex flex-col">
                    <DialogHeader className="flex flex-row items-center justify-between space-y-0">
                        <DialogTitle>Fichiers disponibles sur MinIO (sources/)</DialogTitle>
                        <Button variant="ghost" size="icon" onClick={fetchFiles} disabled={isLoadingFiles} className="h-8 w-8">
                            <RefreshCcw className={cn("h-4 w-4", isLoadingFiles && "animate-spin")} />
                        </Button>
                    </DialogHeader>

                    <div className="relative my-2">
                        <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-zinc-500" />
                        <Input
                            placeholder="Rechercher un fichier..."
                            className="pl-9"
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                        />
                    </div>

                    <div className="flex-1 overflow-auto border rounded-md">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Nom du fichier</TableHead>
                                    <TableHead className="w-[100px]">Taille</TableHead>
                                    <TableHead className="w-[150px]">Modifié le</TableHead>
                                    <TableHead className="w-[80px] text-right">Action</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {isLoadingFiles ? (
                                    <TableRow>
                                        <TableCell colSpan={4} className="h-24 text-center">
                                            Chargement...
                                        </TableCell>
                                    </TableRow>
                                ) : filteredFiles.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={4} className="h-24 text-center">
                                            Aucun fichier trouvé.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    filteredFiles.map((file) => (
                                        <TableRow key={file.path}>
                                            <TableCell className="font-medium flex items-center gap-2">
                                                <FileIcon className="h-4 w-4 text-blue-500" />
                                                <span className="truncate max-w-[300px]" title={file.name}>{file.name}</span>
                                            </TableCell>
                                            <TableCell className="text-xs text-zinc-500">
                                                {formatSize(file.size)}
                                            </TableCell>
                                            <TableCell className="text-xs text-zinc-500">
                                                {new Date(file.last_modified * 1000).toLocaleDateString()}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    className="text-blue-500 hover:text-blue-600 hover:bg-blue-50 h-8 px-2"
                                                    onClick={() => handleAttachFile(file.path)}
                                                    disabled={isSubmitting}
                                                >
                                                    Attacher
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </div>

                    <DialogFooter className="mt-4">
                        <Button variant="outline" onClick={() => setIsBrowseDialogOpen(false)}>Fermer</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

function cn(...classes: (string | boolean | undefined | null)[]) {
    return classes.filter(Boolean).join(' ');
}

