import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { FileText } from 'lucide-react';
import { Button } from '@/components/ui/button';

interface PdfViewerModalProps {
    isOpen: boolean;
    onClose: () => void;
    pdfUrl: string | null;
    title: string;
}

export default function PdfViewerModal({ isOpen, onClose, pdfUrl, title }: PdfViewerModalProps) {
    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-4xl h-[90vh] flex flex-col p-0 overflow-hidden">
                <DialogHeader className="px-4 py-3 border-b shrink-0 flex flex-row items-center justify-between">
                    <div className="flex items-center gap-2">
                        <FileText className="h-5 w-5" />
                        <div>
                            <DialogTitle className="text-base">{title}</DialogTitle>
                            <DialogDescription className="sr-only">
                                Visualisation du PDF du journal officiel
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>

                <div className="flex-1 bg-zinc-900/5 dark:bg-black/50 relative w-full h-full">
                    {pdfUrl ? (
                        <iframe
                            src={pdfUrl}
                            className="h-full w-full border-0 absolute inset-0"
                            title={`PDF Viewer - ${title}`}
                        />
                    ) : (
                        <div className="flex h-full items-center justify-center text-muted-foreground">
                            Aucun fichier PDF associé à ce journal officiel.
                        </div>
                    )}
                </div>
                
                <div className="p-3 border-t bg-muted/30 shrink-0 flex justify-end">
                    <Button variant="outline" onClick={onClose}>Fermer</Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}