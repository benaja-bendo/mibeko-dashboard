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
    PanelRightClose,
    Link2,
    FileWarning,
    Maximize2
} from 'lucide-react';
import { cn } from '@/lib/utils';
// Note: You need to ensure these actions are correctly imported or passed as props if you want to decouple completely
// For now, I'll import them assuming the path is correct as per existing Workstation.tsx
import { updateSourceUrl } from '@/actions/App/Http/Controllers/CurationController';
import { show as pdfProxy } from '@/actions/App/Http/Controllers/PdfProxyController';

interface Document {
    id: string;
    title: string;
    source_url: string | null;
    status: string;
}

interface PdfViewerProps {
    document: Document;
    collapsed: boolean;
    onToggle: () => void;
}

export default function PdfViewer({ document, collapsed, onToggle }: PdfViewerProps) {
    const [isEditDialogOpen, setIsEditDialogOpen] = useState(false);
    const [urlInput, setUrlInput] = useState(document.source_url || '');
    const [isSubmitting, setIsSubmitting] = useState(false);

    // Synchronize urlInput with document.source_url from database
    useEffect(() => {
        setUrlInput(document.source_url || '');
    }, [document.source_url, document.id]);

    const handleSaveUrl = () => {
        setIsSubmitting(true);
        router.visit(updateSourceUrl.url(document.id), {
            method: 'patch',
            data: { source_url: urlInput || null },
            preserveScroll: true,
            onSuccess: () => setIsEditDialogOpen(false),
            onFinish: () => setIsSubmitting(false),
        });
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
                    <Button variant="ghost" size="sm" onClick={() => setIsEditDialogOpen(true)} className="h-8 w-8 p-0 text-zinc-400 hover:text-zinc-100 hover:bg-zinc-700">
                         <Link2 className="h-4 w-4" />
                    </Button>
                    {document.source_url && (
                        <a 
                            href={pdfProxy.url({ query: { path: document.source_url } })} 
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
                        src={pdfProxy.url({ query: { path: document.source_url } })}
                        className="h-full w-full border-0"
                        title="PDF Viewer"
                    />
                ) : (
                    <div className="flex flex-col items-center justify-center h-full text-zinc-500 p-4 text-center">
                        <FileWarning className="mb-2 h-8 w-8 opacity-50" />
                        <span className="text-sm">Pas de document source</span>
                        <Button variant="link" onClick={() => setIsEditDialogOpen(true)} className="text-blue-400 mt-2">
                            Ajouter une source
                        </Button>
                    </div>
                )}
            </div>

            <Dialog open={isEditDialogOpen} onOpenChange={setIsEditDialogOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Modifier la source du document</DialogTitle>
                    </DialogHeader>
                    <div className="py-2">
                        <Input 
                            value={urlInput} 
                            onChange={e => setUrlInput(e.target.value)} 
                            placeholder="Chemin Minio (ex: bucket/path/to/file.pdf)..." 
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
        </div>
    );
}
