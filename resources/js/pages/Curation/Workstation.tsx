import { useState } from 'react';
import { Head, useForm, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
    ChevronRight,
    ChevronDown,
    FileText,
    Folder,
    Save,
    CheckCircle,
    ArrowLeft,
    Maximize2,
    ExternalLink,
    Pencil,
    FileWarning,
    Link2,
    Link2Off,
    Check,
    Loader2,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { updateSourceUrl, updateContent } from '@/actions/App/Http/Controllers/CurationController';
import { show as pdfProxy } from '@/actions/App/Http/Controllers/PdfProxyController';

// --- Interfaces ---

interface StructureNode {
    id: string;
    type_unite: string;
    numero: string | null;
    titre: string | null;
    tree_path: string;
    children?: StructureNode[]; // We might need to build this tree client-side
}

interface Article {
    id: string;
    numero: string;
    content: string;
    parent_id: string | null;
    order: number;
}

interface Document {
    id: string;
    title: string;
    source_url: string | null;
    status: string;
}

interface Props {
    document: Document;
    structure: StructureNode[]; // Flat list from backend
    articles: Article[];
}

// --- PDF Panel Component ---

const PdfPanel = ({ document }: { document: Document }) => {
    const [isEditDialogOpen, setIsEditDialogOpen] = useState(false);
    const [urlInput, setUrlInput] = useState(document.source_url || '');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [recentlySaved, setRecentlySaved] = useState(false);

    const handleSaveUrl = () => {
        setIsSubmitting(true);
        router.patch(
            updateSourceUrl.url(document.id),
            { source_url: urlInput || null },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setIsEditDialogOpen(false);
                    setRecentlySaved(true);
                    setTimeout(() => setRecentlySaved(false), 2000);
                },
                onFinish: () => setIsSubmitting(false),
            }
        );
    };

    const handleOpenDialog = () => {
        setUrlInput(document.source_url || '');
        setIsEditDialogOpen(true);
    };

    const hasSource = !!document.source_url;

    // Build proxy URL for inline PDF display (fixes Minio/S3 download issue)
    const proxyUrl = hasSource 
        ? pdfProxy.url({ query: { path: document.source_url! } })
        : null;

    return (
        <div className="w-1/2 border-r flex flex-col bg-zinc-900">
            {/* Toolbar */}
            <div className="flex items-center justify-between h-12 px-4 border-b border-zinc-700 bg-zinc-800/80 backdrop-blur-sm">
                <div className="flex items-center gap-3 min-w-0 flex-1">
                    {hasSource ? (
                        <>
                            <div className="flex items-center gap-2">
                                <div className="flex items-center justify-center w-7 h-7 rounded-md bg-emerald-500/20 text-emerald-400">
                                    <Link2 className="h-4 w-4" />
                                </div>
                                <Badge variant="outline" className="bg-emerald-500/10 text-emerald-400 border-emerald-500/30 text-xs">
                                    Source disponible
                                </Badge>
                            </div>
                            <div
                                className="flex items-center gap-1.5 text-xs text-zinc-400 truncate max-w-[200px]"
                                title={document.source_url!}
                            >
                                <span className="truncate">{document.source_url}</span>
                            </div>
                        </>
                    ) : (
                        <div className="flex items-center gap-2">
                            <div className="flex items-center justify-center w-7 h-7 rounded-md bg-amber-500/20 text-amber-400">
                                <Link2Off className="h-4 w-4" />
                            </div>
                            <Badge variant="outline" className="bg-amber-500/10 text-amber-400 border-amber-500/30 text-xs">
                                Aucune source
                            </Badge>
                        </div>
                    )}
                </div>
                
                <Dialog open={isEditDialogOpen} onOpenChange={setIsEditDialogOpen}>
                    <DialogTrigger asChild>
                        <Button 
                            variant="ghost" 
                            size="sm" 
                            className="h-8 gap-1.5 text-zinc-400 hover:text-zinc-100 hover:bg-zinc-700"
                            onClick={handleOpenDialog}
                        >
                            {recentlySaved ? (
                                <>
                                    <Check className="h-3.5 w-3.5 text-emerald-400" />
                                    <span className="text-emerald-400 text-xs">Enregistré</span>
                                </>
                            ) : (
                                <>
                                    <Pencil className="h-3.5 w-3.5" />
                                    <span className="text-xs">{hasSource ? 'Modifier' : 'Ajouter'}</span>
                                </>
                            )}
                        </Button>
                    </DialogTrigger>
                    <DialogContent className="sm:max-w-lg">
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-2">
                                <Link2 className="h-5 w-5 text-primary" />
                                {hasSource ? 'Modifier le chemin source' : 'Ajouter un chemin source'}
                            </DialogTitle>
                            <DialogDescription>
                                Entrez le chemin du fichier PDF dans Minio (ex: dossier/fichier.pdf).
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-4 py-4">
                            <div className="grid gap-2">
                                <Label htmlFor="source_url">Chemin Minio</Label>
                                <Input
                                    id="source_url"
                                    type="text"
                                    placeholder="documents/2024/loi-123.pdf"
                                    value={urlInput}
                                    onChange={(e) => setUrlInput(e.target.value)}
                                    className="font-mono text-sm"
                                />
                            </div>
                            {document.source_url && urlInput !== document.source_url && (
                                <div className="text-xs text-muted-foreground bg-muted/50 p-3 rounded-md">
                                    <p className="font-medium mb-1">Chemin actuel:</p>
                                    <p className="font-mono truncate">{document.source_url}</p>
                                </div>
                            )}
                        </div>
                        <DialogFooter>
                            <Button variant="outline" onClick={() => setIsEditDialogOpen(false)}>
                                Annuler
                            </Button>
                            <Button onClick={handleSaveUrl} disabled={isSubmitting}>
                                {isSubmitting ? (
                                    <>
                                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                        Enregistrement...
                                    </>
                                ) : (
                                    <>
                                        <Save className="mr-2 h-4 w-4" />
                                        Enregistrer
                                    </>
                                )}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>

            {/* PDF Content */}
            <div className="flex-1 relative">
                {hasSource ? (
                    <iframe
                        src={proxyUrl!}
                        className="h-full w-full"
                        title="PDF Source"
                    />
                ) : (
                    <div className="flex h-full items-center justify-center">
                        <div className="text-center max-w-xs">
                            <div className="flex justify-center mb-4">
                                <div className="w-16 h-16 rounded-2xl bg-zinc-800 flex items-center justify-center">
                                    <FileWarning className="h-8 w-8 text-zinc-600" />
                                </div>
                            </div>
                            <h3 className="text-lg font-medium text-zinc-300 mb-2">
                                Aucun PDF source
                            </h3>
                            <p className="text-sm text-zinc-500 mb-4">
                                Ajoutez le chemin du document PDF original (Minio) pour le visualiser ici.
                            </p>
                            <Button 
                                variant="outline" 
                                size="sm"
                                onClick={handleOpenDialog}
                                className="bg-zinc-800 border-zinc-700 hover:bg-zinc-700 text-zinc-300"
                            >
                                <Link2 className="mr-2 h-4 w-4" />
                                Ajouter une source
                            </Button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
};

// --- Tree Node Component ---

const TreeNode = ({
    node,
    level,
    onSelect,
    selectedId,
    articles,
    onSelectArticle
}: {
    node: StructureNode;
    level: number;
    onSelect: (id: string) => void;
    selectedId: string | null;
    articles: Article[];
    onSelectArticle: (article: Article) => void;
}) => {
    const [expanded, setExpanded] = useState(true);
    const nodeArticles = articles.filter(a => a.parent_id === node.id);

    return (
        <div>
            <div
                className={cn(
                    "flex items-center py-1 px-2 cursor-pointer hover:bg-accent/50 rounded-sm text-sm",
                    selectedId === node.id && "bg-accent"
                )}
                style={{ paddingLeft: `${level * 12 + 8}px` }}
                onClick={() => {
                    setExpanded(!expanded);
                    onSelect(node.id);
                }}
            >
                {expanded ? (
                    <ChevronDown className="h-4 w-4 mr-1 text-muted-foreground" />
                ) : (
                    <ChevronRight className="h-4 w-4 mr-1 text-muted-foreground" />
                )}
                <Folder className="h-4 w-4 mr-2 text-blue-500" />
                <span className="font-medium truncate">
                    {node.type_unite} {node.numero}
                </span>
            </div>

            {expanded && (
                <div>
                    {nodeArticles.map(article => (
                        <div
                            key={article.id}
                            className={cn(
                                "flex items-center py-1 px-2 cursor-pointer hover:bg-accent/50 rounded-sm text-sm ml-4",
                                selectedId === article.id && "bg-accent text-accent-foreground"
                            )}
                            style={{ paddingLeft: `${(level + 1) * 12 + 8}px` }}
                            onClick={(e) => {
                                e.stopPropagation();
                                onSelectArticle(article);
                            }}
                        >
                            <FileText className="h-3 w-3 mr-2 text-gray-500" />
                            <span className="truncate">
                                Article {article.numero}
                            </span>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
};

// --- Main Page ---

export default function Workstation({ document, structure, articles }: Props) {
    const [selectedArticle, setSelectedArticle] = useState<Article | null>(null);
    
    // Form for editing article
    const { data, setData, post, processing, recentlySuccessful } = useForm({
        content: '',
        article_id: ''
    });

    // Update form when article changes
    const handleSelectArticle = (article: Article) => {
        setSelectedArticle(article);
        setData({
            content: article.content,
            article_id: article.id
        });
    };

    const handleSave = () => {
        if (!selectedArticle) return;
        post(updateContent.url(document.id), {
            preserveScroll: true,
            onSuccess: () => {
                // Optimistically update the local article list?
                // For now, Inertia reload will handle it, but might be slow.
                // Ideally we update local state.
            }
        });
    };

    // Helper to calculate depth from tree_path (e.g. "root.livre1.titre2" -> depth 2)
    const getDepth = (path: string) => (path.match(/\./g) || []).length;

    return (
        <div className="flex h-screen flex-col overflow-hidden bg-background">
            {/* Header */}
            <header className="flex h-14 items-center gap-4 border-b bg-muted/40 px-6">
                <Link href="/curation">
                    <Button variant="ghost" size="icon">
                        <ArrowLeft className="h-5 w-5" />
                    </Button>
                </Link>
                <div className="flex-1">
                    <h1 className="text-lg font-semibold">{document.title}</h1>
                </div>
                <div className="flex items-center gap-2">
                    <Badge variant="outline" className="uppercase">
                        {document.status}
                    </Badge>
                    <Button size="sm" onClick={handleSave} disabled={processing || !selectedArticle}>
                        <Save className="mr-2 h-4 w-4" />
                        {recentlySuccessful ? 'Enregistré' : 'Enregistrer'}
                    </Button>
                </div>
            </header>

            {/* Main Split View */}
            <div className="flex flex-1 overflow-hidden">
                {/* Left: PDF Viewer */}
                <PdfPanel document={document} />

                {/* Right: Editor & Tree */}
                <div className="flex w-1/2 flex-col">
                    {/* Toolbar */}
                    <div className="flex items-center justify-between border-b p-2 px-4 bg-muted/10">
                        <span className="text-sm font-medium text-muted-foreground">
                            Structure & Contenu
                        </span>
                        <div className="flex gap-1">
                            {/* Tools like Search/Replace could go here */}
                        </div>
                    </div>

                    <div className="flex flex-1 overflow-hidden">
                        {/* Structure Tree (Left side of Right Pane) */}
                        <div className="w-1/3 border-r overflow-y-auto bg-muted/5 p-2">
                            {structure.map((node) => {
                                const depth = getDepth(node.tree_path);
                                // Simple linear rendering with indentation
                                // This assumes 'structure' is sorted by tree_path
                                return (
                                    <TreeNode
                                        key={node.id}
                                        node={node}
                                        level={depth}
                                        onSelect={() => {}}
                                        selectedId={selectedArticle?.id || null}
                                        articles={articles}
                                        onSelectArticle={handleSelectArticle}
                                    />
                                );
                            })}
                            
                            {/* Orphan Articles (no parent) */}
                            {articles.filter(a => !a.parent_id).map(article => (
                                <div
                                    key={article.id}
                                    className={cn(
                                        "flex items-center py-1 px-2 cursor-pointer hover:bg-accent/50 rounded-sm text-sm",
                                        selectedArticle?.id === article.id && "bg-accent text-accent-foreground"
                                    )}
                                    onClick={() => handleSelectArticle(article)}
                                >
                                    <FileText className="h-3 w-3 mr-2 text-gray-500" />
                                    <span className="truncate">
                                        Article {article.numero}
                                    </span>
                                </div>
                            ))}
                        </div>

                        {/* Editor (Right side of Right Pane) */}
                        <div className="flex-1 flex flex-col p-4 overflow-y-auto">
                            {selectedArticle ? (
                                <div className="space-y-4 h-full flex flex-col">
                                    <div>
                                        <h2 className="text-lg font-bold flex items-center gap-2">
                                            Article {selectedArticle.numero}
                                            {selectedArticle.parent_id && (
                                                <Badge variant="secondary" className="text-xs font-normal">
                                                    Lié à la structure
                                                </Badge>
                                            )}
                                        </h2>
                                    </div>
                                    <div className="flex-1">
                                        <textarea
                                            className="w-full h-full min-h-[400px] p-4 rounded-md border resize-none focus:outline-none focus:ring-2 focus:ring-ring bg-background font-mono text-sm leading-relaxed"
                                            value={data.content}
                                            onChange={(e) => setData('content', e.target.value)}
                                            placeholder="Contenu de l'article..."
                                        />
                                    </div>
                                </div>
                            ) : (
                                <div className="flex h-full items-center justify-center text-muted-foreground">
                                    Sélectionnez un article pour l'éditer
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}

