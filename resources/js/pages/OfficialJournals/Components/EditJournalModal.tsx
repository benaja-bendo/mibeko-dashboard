import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { useForm } from '@inertiajs/react';
import { FormEvent, useEffect } from 'react';
import { toast } from 'sonner';
import { OfficialJournal } from '../types';

interface EditJournalModalProps {
    isOpen: boolean;
    onClose: () => void;
    journal: OfficialJournal | null;
}

export default function EditJournalModal({ isOpen, onClose, journal }: EditJournalModalProps) {
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm<{
        title: string;
        publication_date: string;
        is_published: boolean;
        file: File | null;
        _method: string;
    }>({
        title: '',
        publication_date: '',
        is_published: false,
        file: null,
        _method: 'post', // Needed for file uploads in Laravel when acting as PUT/PATCH
    });

    useEffect(() => {
        if (journal) {
            setData({
                title: journal.title,
                publication_date: journal.publication_date ? journal.publication_date.split('T')[0] : '',
                is_published: journal.is_published,
                file: null,
                _method: 'post',
            });
        }
    }, [journal]);

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        
        if (!journal) return;

        post(`/official-journals/${journal.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Journal Officiel modifié avec succès');
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

    if (!journal) return null;

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && handleClose()}>
            <DialogContent className="sm:max-w-[425px]">
                <form onSubmit={handleSubmit}>
                    <DialogHeader>
                        <DialogTitle>Modifier le Journal Officiel</DialogTitle>
                        <DialogDescription>
                            Mettez à jour les informations du J.O. ou remplacez le fichier PDF.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        <div className="grid gap-2">
                            <Label htmlFor="edit-title">Titre du J.O.</Label>
                            <Input
                                id="edit-title"
                                value={data.title}
                                onChange={(e) => setData('title', e.target.value)}
                            />
                            {errors.title && <span className="text-sm text-destructive">{errors.title}</span>}
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="edit-publication_date">Date de publication</Label>
                            <Input
                                id="edit-publication_date"
                                type="date"
                                value={data.publication_date}
                                onChange={(e) => setData('publication_date', e.target.value)}
                            />
                            {errors.publication_date && <span className="text-sm text-destructive">{errors.publication_date}</span>}
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="edit-file">Remplacer le fichier PDF (Optionnel)</Label>
                            <Input
                                id="edit-file"
                                type="file"
                                accept=".pdf"
                                onChange={(e) => setData('file', e.target.files?.[0] || null)}
                            />
                            {errors.file && <span className="text-sm text-destructive">{errors.file}</span>}
                            <p className="text-xs text-muted-foreground">
                                Laissez vide si vous ne souhaitez pas modifier le fichier existant.
                            </p>
                        </div>

                        <div className="flex items-center space-x-2 pt-2">
                            <Checkbox 
                                id="edit-is_published" 
                                checked={data.is_published}
                                onCheckedChange={(checked) => setData('is_published', checked as boolean)}
                            />
                            <Label htmlFor="edit-is_published" className="font-normal cursor-pointer">
                                Rendre public
                            </Label>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={handleClose}>
                            Annuler
                        </Button>
                        <Button type="submit" disabled={processing || !data.title}>
                            {processing ? 'Enregistrement...' : 'Enregistrer'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}