import { useState, useEffect } from 'react';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Separator } from '@/components/ui/separator';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Save,
    History,
    ChevronRight,
    FileText,
    AlertCircle,
    PanelRight,
    PanelRightClose,
    Plus,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import StatusBadge from '@/pages/Curation/Components/StatusBadge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from "@/components/ui/tooltip";

interface Article {
    id: string;
    numero: string;
    content: string;
    parent_id: string | null;
    order: number;
    status: 'pending' | 'in_progress' | 'validated';
}

interface NewVersionData {
    content: string;
    reason?: string;
    validFrom?: string;
}

interface ContentEditorProps {
    article: Article | null;
    breadcrumbs: { title: string; type: string }[];
    onSave: (id: string, content: string) => void;
    onCreateNewVersion?: (id: string, data: NewVersionData) => void;
    onUpdateStatus: (id: string, status: string) => void;
    currentDocumentId: string;
    isPdfVisible: boolean;
    onTogglePdf: () => void;
}

export default function ContentEditor({
    article,
    breadcrumbs,
    onSave,
    onCreateNewVersion,
    onUpdateStatus,
    currentDocumentId,
    isPdfVisible,
    onTogglePdf
}: ContentEditorProps) {
    const [content, setContent] = useState('');
    const [isDirty, setIsDirty] = useState(false);
    const [newVersionDialogOpen, setNewVersionDialogOpen] = useState(false);
    const [newVersionData, setNewVersionData] = useState({
        reason: '',
        validFrom: new Date().toISOString().split('T')[0],
    });

    // Reset state when article changes
    useEffect(() => {
        if (article) {
            setContent(article.content || '');
            setIsDirty(false);
        }
    }, [article?.id]);

    const handleContentChange = (e: React.ChangeEvent<HTMLTextAreaElement>) => {
        setContent(e.target.value);
        setIsDirty(true);
    };

    const handleSave = () => {
        if (article) {
            onSave(article.id, content);
            setIsDirty(false);
        }
    };

    const handleOpenNewVersionDialog = () => {
        setNewVersionData({
            reason: '',
            validFrom: new Date().toISOString().split('T')[0],
        });
        setNewVersionDialogOpen(true);
    };

    const handleCreateNewVersion = () => {
        if (article && onCreateNewVersion) {
            onCreateNewVersion(article.id, {
                content,
                reason: newVersionData.reason,
                validFrom: newVersionData.validFrom,
            });
            setNewVersionDialogOpen(false);
            setIsDirty(false);
        }
    };

    if (!article) {
        return (
            <div className="h-full flex flex-col items-center justify-center text-zinc-400 bg-white dark:bg-zinc-950">
                <div className="bg-zinc-50 dark:bg-zinc-900 p-4 rounded-full mb-4">
                    <FileText className="h-8 w-8 text-zinc-300" />
                </div>
                <p className="text-sm font-medium text-zinc-500">Sélectionnez un article pour l'éditer</p>
            </div>
        );
    }

    return (
        <div className="h-full flex flex-col bg-white dark:bg-zinc-950 font-sans relative">
             {/* Header with Breadcrumb */}
             <div className="border-b border-zinc-200 dark:border-zinc-800 shrink-0 bg-white dark:bg-zinc-950 z-20">
                {/* Top Bar: Breadcrumb + PDF Toggle */}
                <div className="px-6 py-2 border-b border-zinc-100 dark:border-zinc-800 flex items-center justify-between h-12">
                     <nav className="flex items-center text-xs text-zinc-500 overflow-hidden">
                        {breadcrumbs.map((crumb, i) => (
                            <div key={i} className="flex items-center whitespace-nowrap">
                                {i > 0 && <ChevronRight className="h-3 w-3 mx-1 text-zinc-300" />}
                                <span className={cn(
                                    "truncate max-w-[200px] transition-colors",
                                    i === breadcrumbs.length - 1
                                        ? "font-medium text-zinc-900 dark:text-zinc-100 bg-zinc-100 dark:bg-zinc-800 px-1.5 py-0.5 rounded-md"
                                        : "hover:text-zinc-800 cursor-default"
                                )}>
                                    {crumb.title}
                                </span>
                            </div>
                        ))}
                    </nav>

                    {/* PDF Toggle Button */}
                    <div className="flex items-center gap-2">
                         <TooltipProvider>
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={onTogglePdf}
                                        className={cn(
                                            "h-8 gap-2 text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-100",
                                            !isPdfVisible && "text-blue-600 bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/20 dark:text-blue-400 dark:hover:bg-blue-900/30"
                                        )}
                                    >
                                        {isPdfVisible ? (
                                            <>
                                                <PanelRightClose className="h-4 w-4" />
                                                <span className="hidden lg:inline text-xs">Masquer PDF</span>
                                            </>
                                        ) : (
                                            <>
                                                <PanelRight className="h-4 w-4" />
                                                <span className="hidden lg:inline text-xs">Afficher PDF</span>
                                            </>
                                        )}
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>
                                    <p>{isPdfVisible ? "Masquer le visualisateur PDF" : "Afficher le visualisateur PDF"}</p>
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </div>
                </div>

                {/* Article Title & Status Bar */}
                <div className="px-6 py-4 flex items-start justify-between bg-zinc-50/30 dark:bg-zinc-900/10">
                    <div className="flex flex-col gap-2">
                        <div className="flex items-center gap-3">
                            <h2 className="text-2xl font-bold font-serif text-zinc-900 dark:text-zinc-50 tracking-tight">
                                Article {article.numero}
                            </h2>
                            <StatusBadge
                                status={article.status}
                                onChange={(s) => onUpdateStatus(article.id, s)}
                            />
                        </div>
                        <div className="flex items-center gap-4 text-xs text-zinc-400">
                             <span>Dernière modification: Il y a 2h (Placeholder)</span>
                             {isDirty && (
                                <span className="text-amber-600 flex items-center gap-1 font-medium animate-pulse">
                                    <AlertCircle className="h-3 w-3" />
                                    Modifications non enregistrées
                                </span>
                            )}
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        {/* Save current version button */}
                        <TooltipProvider>
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={handleSave}
                                        disabled={!isDirty}
                                        className={cn(
                                            "transition-all",
                                            isDirty ? "border-blue-300 text-blue-600 hover:bg-blue-50 dark:border-blue-700 dark:text-blue-400 dark:hover:bg-blue-900/20" : ""
                                        )}
                                    >
                                        <Save className="h-4 w-4 mr-2" />
                                        Enregistrer
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>
                                    <p>Modifier la version actuelle sans créer d'historique</p>
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>

                        {/* New version button */}
                        <TooltipProvider>
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <Button
                                        size="sm"
                                        variant="default"
                                        onClick={handleOpenNewVersionDialog}
                                        disabled={!isDirty}
                                        className={cn(
                                            "transition-all",
                                            isDirty ? "bg-emerald-600 hover:bg-emerald-700 shadow-md shadow-emerald-900/20" : "bg-zinc-200 text-zinc-400 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-500"
                                        )}
                                    >
                                        <Plus className="h-4 w-4 mr-2" />
                                        Nouvelle version
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>
                                    <p>Créer une nouvelle version avec historique complet</p>
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </div>
                </div>
            </div>


            <Tabs defaultValue="editor" className="flex-1 flex flex-col min-h-0">
                 <div className="px-6 border-b border-zinc-100 dark:border-zinc-800 shrink-0 bg-white dark:bg-zinc-950 flex items-center justify-between">
                    <TabsList className="h-10 bg-transparent p-0 w-auto gap-6">
                        <TabsTrigger
                            value="editor"
                            className="h-10 rounded-none border-b-2 border-transparent data-[state=active]:border-blue-600 data-[state=active]:shadow-none px-0 bg-transparent"
                        >
                            Éditeur
                        </TabsTrigger>
                        <TabsTrigger
                            value="history"
                            className="h-10 rounded-none border-b-2 border-transparent data-[state=active]:border-blue-600 data-[state=active]:shadow-none px-0 bg-transparent"
                        >
                            <History className="h-3.5 w-3.5 mr-1.5" />
                            Versions
                        </TabsTrigger>
                    </TabsList>
                 </div>

                <TabsContent value="editor" className="flex-1 min-h-0 m-0 relative flex flex-col">
                     {/* Toolbar Placeholder */}
                    <div className="px-6 py-2 border-b border-zinc-50 dark:border-zinc-800 flex items-center gap-1 text-zinc-500 text-xs shrink-0 bg-zinc-50/50 dark:bg-zinc-900/50">
                         <div className="flex items-center gap-1 mr-4">
                            <div className="px-2 py-1 rounded hover:bg-zinc-200 dark:hover:bg-zinc-700 cursor-pointer font-serif font-bold text-zinc-700 dark:text-zinc-300">B</div>
                            <div className="px-2 py-1 rounded hover:bg-zinc-200 dark:hover:bg-zinc-700 cursor-pointer italic font-serif text-zinc-700 dark:text-zinc-300">I</div>
                            <div className="px-2 py-1 rounded hover:bg-zinc-200 dark:hover:bg-zinc-700 cursor-pointer underline text-zinc-700 dark:text-zinc-300">U</div>
                         </div>
                         <Separator orientation="vertical" className="h-4 mr-4" />
                         <span className="text-zinc-400">Mode WYSIWYG</span>
                    </div>

                    <div className="flex-1 overflow-y-auto bg-white dark:bg-zinc-950">
                        <div className="max-w-3xl mx-auto py-12 px-8 min-h-full">
                             <Textarea
                                value={content}
                                onChange={handleContentChange}
                                className="w-full h-full min-h-[60vh] resize-none border-0 focus-visible:ring-0 p-0 text-lg leading-loose font-serif text-zinc-800 dark:text-zinc-300 bg-transparent placeholder:text-zinc-300 selection:bg-blue-100 dark:selection:bg-blue-900/30"
                                placeholder="Saisissez le texte de l'article ici..."
                                spellCheck={false}
                            />
                        </div>
                    </div>
                </TabsContent>

                <TabsContent value="history" className="flex-1 overflow-y-auto p-6">
                    <div className="text-center text-zinc-500 text-sm py-12">
                        <History className="h-12 w-12 mx-auto mb-4 opacity-10" />
                        <h3 className="font-medium text-zinc-900 dark:text-zinc-100 mb-1">Historique des versions</h3>
                        <p className="text-zinc-400 text-xs">Les modifications sont enregistrées automatiquement.</p>
                    </div>
                </TabsContent>
            </Tabs>

            {/* New Version Dialog */}
            <Dialog open={newVersionDialogOpen} onOpenChange={setNewVersionDialogOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Créer une nouvelle version</DialogTitle>
                        <DialogDescription>
                            Cette action créera une nouvelle version de l'article avec un historique complet.
                            L'ancienne version sera conservée pour référence.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4 py-4">
                        <div className="space-y-2">
                            <Label htmlFor="validFrom">Date d'entrée en vigueur</Label>
                            <Input
                                id="validFrom"
                                type="date"
                                value={newVersionData.validFrom}
                                onChange={(e) => setNewVersionData(prev => ({ ...prev, validFrom: e.target.value }))}
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="reason">Motif de la modification (optionnel)</Label>
                            <Textarea
                                id="reason"
                                value={newVersionData.reason}
                                onChange={(e) => setNewVersionData(prev => ({ ...prev, reason: e.target.value }))}
                                placeholder="Ex: Mise à jour suite à la loi n°..."
                                className="min-h-[80px]"
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setNewVersionDialogOpen(false)}>
                            Annuler
                        </Button>
                        <Button
                            onClick={handleCreateNewVersion}
                            className="bg-emerald-600 hover:bg-emerald-700"
                        >
                            <Plus className="h-4 w-4 mr-2" />
                            Créer la version
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

