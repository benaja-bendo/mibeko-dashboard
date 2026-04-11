import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { toast } from 'sonner';

interface UploadJournalModalProps {
    isOpen: boolean;
    onClose: () => void;
}

export default function UploadJournalModal({ isOpen, onClose }: UploadJournalModalProps) {
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm<{
        title: string;
        publication_date: string;
        is_published: boolean;
        file: File | null;
    }>({
        title: '',
        publication_date: '',
        is_published: false,
        file: null,
    });

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();

        post('/official-journals', {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Journal Officiel ajouté avec succès');
                reset();
                onClose();
            },
        });
    };

    const handleClose = () => {
        reset();
        clearErrors();
        onClose();
    };

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && handleClose()}>
            <DialogContent className="sm:max-w-[425px]">
                <form onSubmit={handleSubmit}>
                    <DialogHeader>
                        <DialogTitle>Nouveau Journal Officiel</DialogTitle>
                        <DialogDescription>
                            Ajoutez un nouveau PDF du Journal Officiel pour extraction et diffusion.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        <div className="grid gap-2">
                            <Label htmlFor="title">Titre du J.O.</Label>
                            <Input
                                id="title"
                                placeholder="ex: Journal Officiel n° 12 de 2026"
                                value={data.title}
                                onChange={(e) => setData('title', e.target.value)}
                            />
                            {errors.title && <span className="text-sm text-destructive">{errors.title}</span>}
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="publication_date">Date de publication</Label>
                            <Input
                                id="publication_date"
                                type="date"
                                value={data.publication_date}
                                onChange={(e) => setData('publication_date', e.target.value)}
                            />
                            {errors.publication_date && <span className="text-sm text-destructive">{errors.publication_date}</span>}
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="file">Fichier PDF</Label>
                            <Input
                                id="file"
                                type="file"
                                accept=".pdf"
                                onChange={(e) => setData('file', e.target.files?.[0] || null)}
                            />
                            {errors.file && <span className="text-sm text-destructive">{errors.file}</span>}
                        </div>

                        <div className="flex items-center space-x-2 pt-2">
                            <Checkbox 
                                id="is_published" 
                                checked={data.is_published}
                                onCheckedChange={(checked) => setData('is_published', checked as boolean)}
                            />
                            <Label htmlFor="is_published" className="font-normal cursor-pointer">
                                Rendre immédiatement public
                            </Label>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={handleClose}>
                            Annuler
                        </Button>
                        <Button type="submit" disabled={processing || !data.file || !data.title}>
                            {processing ? 'Enregistrement...' : 'Enregistrer et Uploader'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}