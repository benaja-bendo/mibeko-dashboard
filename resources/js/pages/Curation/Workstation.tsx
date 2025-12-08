import { useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { ScrollArea } from '@/components/ui/scroll-area'; // Assuming this exists or I'll use div overflow
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea'; // Need to check if this exists
import {
    ChevronRight,
    ChevronDown,
    FileText,
    Folder,
    Save,
    CheckCircle,
    ArrowLeft,
    Maximize2
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';

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

// --- Components ---

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
                    {/* Recursive children would go here if we had a nested structure,
                        but for now we are assuming flat structure from backend needs processing
                        or we just render flat list if backend sends it sorted by path.
                        
                        Wait, the backend sends a flat list of nodes.
                        I need to reconstruct the tree or just render them in order if they are sorted by tree_path.
                        If they are sorted by tree_path, I can just render them linearly and use indentation based on tree_path depth.
                    */}
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
        post(route('curation.content.update', { document: document.id }), {
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
                <div className="w-1/2 border-r bg-gray-100 relative">
                    {document.source_url ? (
                        <iframe
                            src={document.source_url}
                            className="h-full w-full"
                            title="PDF Source"
                        />
                    ) : (
                        <div className="flex h-full items-center justify-center text-muted-foreground">
                            Aucun PDF source disponible
                        </div>
                    )}
                </div>

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
