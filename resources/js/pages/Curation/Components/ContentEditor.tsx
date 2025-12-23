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
    History as HistoryIcon,
    ChevronRight,
    FileText,
    AlertCircle,
    PanelRight,
    PanelRightClose,
    Plus,
    ChevronLeft,
    Layers,
    Calendar,
    Settings,
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

interface Document {
    id: string;
    title: string;
    source_url: string | null;
    status: string;
    date_signature: string | null;
    date_publication: string | null;
}

interface StructureNode {
    id: string;
    type_unite: string;
    numero: string | null;
    titre: string | null;
    status: 'pending' | 'in_progress' | 'validated';
}

interface ContentEditorProps {
    document: Document;
    node: StructureNode | null;
    article: Article | null;
    selectedType: 'document' | 'node' | 'article';
    prevArticle: Article | null;
    nextArticle: Article | null;
    onSelectArticle: (article: Article) => void;
    breadcrumbs: { title: string; type: string }[];
    onSave: (id: string, content: string) => void;
    onCreateNewVersion?: (id: string, data: NewVersionData) => void;
    onUpdateStatus: (id: string, type: 'node' | 'article', status: string) => void;
    onUpdateDocument: (data: Partial<Document>) => void;
    onUpdateNode: (id: string, data: Partial<StructureNode>) => void;
    isPdfVisible: boolean;
    onTogglePdf: () => void;
}

export default function ContentEditor({
    document,
    node,
    article,
    selectedType,
    prevArticle,
    nextArticle,
    onSelectArticle,
    breadcrumbs,
    onSave,
    onCreateNewVersion,
    onUpdateStatus,
    onUpdateDocument,
    onUpdateNode,
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

    // --- Renders ---

    const renderDocumentOptions = () => (
        <div className="flex-1 overflow-y-auto bg-zinc-50/30 dark:bg-zinc-950 p-8">
            <div className="max-w-2xl mx-auto space-y-8">
                <div className="space-y-2">
                    <h2 className="text-2xl font-bold flex items-center gap-2">
                        <Settings className="h-6 w-6 text-blue-500" />
                        Options du Document
                    </h2>
                    <p className="text-muted-foreground text-sm">Gérez les métadonnées globales de ce texte juridique.</p>
                </div>

                <div className="grid gap-6 p-6 border rounded-xl bg-white dark:bg-zinc-900 shadow-sm">
                    <div className="space-y-4">
                        <div className="grid gap-2">
                            <Label>Statut Curation Global</Label>
                            <div className="flex flex-wrap gap-2">
                                {['draft', 'review', 'validated', 'published'].map((s) => (
                                    <Button
                                        key={s}
                                        size="sm"
                                        variant={document.status === s ? 'default' : 'outline'}
                                        onClick={() => onUpdateDocument({ status: s })}
                                        className="h-8 px-3 text-xs capitalize"
                                    >
                                        {s === 'draft' ? 'Brouillon' : s === 'review' ? 'En revue' : s === 'validated' ? 'Validé' : 'Publié'}
                                    </Button>
                                ))}
                            </div>
                        </div>

                        <Separator />

                        <div className="grid grid-cols-2 gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="date_signature" className="flex items-center gap-2">
                                    <Calendar className="h-4 w-4 text-zinc-400" />
                                    Date de signature
                                </Label>
                                <Input
                                    id="date_signature"
                                    type="date"
                                    value={document.date_signature || ''}
                                    onChange={(e) => onUpdateDocument({ date_signature: e.target.value })}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="date_publication" className="flex items-center gap-2">
                                    <Calendar className="h-4 w-4 text-zinc-400" />
                                    Date de publication
                                </Label>
                                <Input
                                    id="date_publication"
                                    type="date"
                                    value={document.date_publication || ''}
                                    onChange={(e) => onUpdateDocument({ date_publication: e.target.value })}
                                />
                            </div>
                        </div>

                        <div className="grid gap-2 mt-4">
                             <Label>Lien source / PDF</Label>
                             <div className="flex gap-2">
                                <Input value={document.source_url || ''} disabled className="bg-zinc-50 dark:bg-zinc-800" />
                                <Button size="sm" variant="ghost">Changer</Button>
                             </div>
                        </div>
                    </div>
                </div>
                
                <div className="p-4 border border-blue-100 bg-blue-50/50 dark:bg-blue-900/10 dark:border-blue-900/30 rounded-lg flex gap-3">
                    <AlertCircle className="h-5 w-5 text-blue-500 shrink-0" />
                    <p className="text-xs text-blue-700 dark:text-blue-300 leading-relaxed">
                        Ces dates sont essentielles pour le calcul de la validité temporelle des articles. 
                        La date de publication est utilisée par défaut comme date d'entrée en vigueur si non spécifiée autrement.
                    </p>
                </div>
            </div>
        </div>
    );

    const renderNodeOptions = () => (
        <div className="flex-1 overflow-y-auto bg-zinc-50/30 dark:bg-zinc-950 p-8">
            {node ? (
                <div className="max-w-2xl mx-auto space-y-8">
                    <div className="space-y-2">
                        <h2 className="text-2xl font-bold flex items-center gap-2">
                            <Layers className="h-6 w-6 text-emerald-500" />
                            Structure : {node.type_unite} {node.numero}
                        </h2>
                        <p className="text-muted-foreground text-sm">Modifiez les propriétés de cet élément de structure.</p>
                    </div>

                    <div className="grid gap-6 p-6 border rounded-xl bg-white dark:bg-zinc-900 shadow-sm">
                        <div className="grid grid-cols-2 gap-4">
                            <div className="grid gap-2">
                                <Label>Type d'unité</Label>
                                <Input value={node.type_unite} onChange={e => onUpdateNode(node.id, { type_unite: e.target.value })} />
                            </div>
                            <div className="grid gap-2">
                                <Label>Numéro</Label>
                                <Input value={node.numero || ''} onChange={e => onUpdateNode(node.id, { numero: e.target.value })} />
                            </div>
                        </div>
                        <div className="grid gap-2">
                            <Label>Titre / Libellé</Label>
                            <Input value={node.titre || ''} onChange={e => onUpdateNode(node.id, { titre: e.target.value })} />
                        </div>
                        <div className="grid gap-2">
                            <Label>Statut de validation</Label>
                            <StatusBadge
                                status={node.status}
                                onChange={(s) => onUpdateStatus(node.id, 'node', s)}
                            />
                        </div>
                    </div>
                </div>
            ) : (
                <div className="h-full flex flex-col items-center justify-center text-zinc-400">
                    <AlertCircle className="h-10 w-10 opacity-20 mb-4" />
                    <p>Élément introuvable</p>
                </div>
            )}
        </div>
    );

    const renderArticleEditor = () => (
        <>
            {/* Article Title & Status Bar */}
            <div className="px-6 py-4 flex items-start justify-between bg-zinc-50/30 dark:bg-zinc-900/10 border-b">
                <div className="flex flex-col gap-2">
                    <div className="flex items-center gap-3">
                        <h2 className="text-2xl font-bold font-serif text-zinc-900 dark:text-zinc-50 tracking-tight">
                            Article {article?.numero}
                        </h2>
                        {article && (
                            <StatusBadge
                                status={article.status}
                                onChange={(s) => onUpdateStatus(article.id, 'article', s)}
                            />
                        )}
                    </div>
                    <div className="flex items-center gap-4">
                        <div className="flex items-center gap-2">
                            <Button
                                size="sm"
                                variant="ghost"
                                className="h-7 px-2 text-[10px]"
                                disabled={!prevArticle}
                                onClick={() => prevArticle && onSelectArticle(prevArticle)}
                            >
                                <ChevronLeft className="h-3 w-3 mr-1" />
                                Précédent
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                className="h-7 px-2 text-[10px]"
                                disabled={!nextArticle}
                                onClick={() => nextArticle && onSelectArticle(nextArticle)}
                            >
                                Suivant
                                <ChevronRight className="h-3 w-3 ml-1" />
                            </Button>
                        </div>
                        <Separator orientation="vertical" className="h-4" />
                        <div className="flex items-center gap-4 text-xs text-zinc-400">
                             {isDirty && (
                                <span className="text-amber-600 flex items-center gap-1 font-medium animate-pulse">
                                    <AlertCircle className="h-3 w-3" />
                                    Modifications non enregistrées
                                </span>
                            )}
                        </div>
                    </div>
                </div>

                <div className="flex items-center gap-2">
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
                            <HistoryIcon className="h-3.5 w-3.5 mr-1.5" />
                            Versions
                        </TabsTrigger>
                    </TabsList>
                 </div>

                <TabsContent value="editor" className="flex-1 min-h-0 m-0 relative flex flex-col">
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
                        <HistoryIcon className="h-12 w-12 mx-auto mb-4 opacity-10" />
                        <h3 className="font-medium text-zinc-900 dark:text-zinc-100 mb-1">Historique des versions</h3>
                        <p className="text-zinc-400 text-xs">Les modifications sont enregistrées automatiquement.</p>
                    </div>
                </TabsContent>
            </Tabs>
        </>
    );

    return (
        <div className="h-full flex flex-col bg-white dark:bg-zinc-950 font-sans relative overflow-hidden">
             {/* Header with Breadcrumb */}
             <div className="border-b border-zinc-200 dark:border-zinc-800 shrink-0 bg-white dark:bg-zinc-950 z-20">
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
            </div>

            <div className="flex-1 flex flex-col min-h-0">
                {selectedType === 'document' ? renderDocumentOptions() :
                 selectedType === 'node' ? renderNodeOptions() :
                 renderArticleEditor()}
            </div>

            <Dialog open={newVersionDialogOpen} onOpenChange={setNewVersionDialogOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Créer une nouvelle version</DialogTitle>
                        <DialogDescription>
                            Cette action créera une nouvelle version de l'article avec un historique complet.
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
                            <Label htmlFor="reason">Motif (optionnel)</Label>
                            <Textarea
                                id="reason"
                                value={newVersionData.reason}
                                onChange={(e) => setNewVersionData(prev => ({ ...prev, reason: e.target.value }))}
                                placeholder="Motif de la modification..."
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setNewVersionDialogOpen(false)}>Annuler</Button>
                        <Button onClick={handleCreateNewVersion} className="bg-emerald-600 hover:bg-emerald-700">
                            <Plus className="h-4 w-4 mr-2" />
                            Créer la version
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
