import { useState, useEffect, useMemo } from 'react';
import { Head, useForm, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import { storeNode, updateNode, destroyNode, storeArticle, updateArticle, destroyArticle, reorder } from '@/actions/App/Http/Controllers/CurationController';
import WorkstationLayout from './Components/WorkstationLayout';
import { StructureNode, Article, TreeActions } from './Components/StructureTree';
import { arrayMove } from '@dnd-kit/sortable';
import { DragEndEvent } from '@dnd-kit/core';

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

export default function Workstation({ document, structure: initialStructure, articles: initialArticles }: Props) {
    const [structure, setStructure] = useState<StructureNode[]>([]);
    const [articles, setArticles] = useState<Article[]>(initialArticles);
    const [selectedArticleId, setSelectedArticleId] = useState<string | null>(null);
    const [selectedNodeId, setSelectedNodeId] = useState<string | null>(null);

    // Initialize structure
    useEffect(() => {
        setStructure(initialStructure.sort((a, b) => a.order - b.order));
        setArticles(initialArticles.sort((a, b) => a.order - b.order));
    }, [initialStructure, initialArticles]);

    // Build hierarchical tree from flat structure
    const treeStructure = useMemo(() => {
        const nodeMap = new Map<string, StructureNode>();
        // Create copies of nodes to avoid mutating state directly and add children array
        structure.forEach(node => {
            nodeMap.set(node.id, { ...node, children: [] });
        });

        const roots: StructureNode[] = [];

        // Sort by tree_path to ensure parents are processed before children if possible,
        // though map lookup handles order independence.
        // We rely on tree_path length or hierarchy.

        structure.forEach(originalNode => {
            const node = nodeMap.get(originalNode.id)!;
            const pathParts = (node.tree_path || '').split('.');

            // Heuristic: If path has > 2 parts (root.timestamp.timestamp), it has a parent.
            // But strict ID matching is better if we had parent_id.
            // Since we don't have explicit parent_id in the interface, we rely on path logic or finding the parent in the map.
            // Actually, if tree_path is "root.ID1.ID2", ID2 is child of ID1.
            // We need to match the "ID" part.
            // BUT the IDs generated in handleNodeSubmit are `Date.now()`.
            // The `id` field from DB is a UUID (presumably).
            // Let's assume tree_path stores IDs or we must fallback to flat list if parsing fails.

            // Fallback: If we can't reliably determine hierarchy from path without parent_id column,
            // we might be stuck.
            // However, `StructureTree` component logic I wrote uses `node.children`.
            // Let's try to infer parent from path:
            // Path: "root.A.B" -> Parent Path: "root.A"
            // Find node with tree_path === "root.A"

            const lastDotIndex = node.tree_path.lastIndexOf('.');
            if (lastDotIndex > 0) {
                const parentPath = node.tree_path.substring(0, lastDotIndex);
                if (parentPath === 'root') {
                    roots.push(node);
                } else {
                    // Find parent by path
                    // This is slow O(N^2) effectively if we search array, but with Map we need to index by Path.
                    // Let's map by path too.
                    // But we can't because we iterate structure.
                }
            } else {
                roots.push(node);
            }
        });

        // BETTER APPROACH: Index by tree_path
        const pathMap = new Map<string, StructureNode>();
        structure.forEach(node => {
            const n = { ...node, children: [] };
            nodeMap.set(node.id, n);
            pathMap.set(node.tree_path, n);
        });

        const treeRoots: StructureNode[] = [];

        structure.forEach(node => {
            const current = nodeMap.get(node.id)!;
            const parts = node.tree_path.split('.');

            if (parts.length <= 2) {
                // e.g. "root.123" -> Root level
                treeRoots.push(current);
            } else {
                // e.g. "root.123.456" -> Parent is "root.123"
                const parentPath = parts.slice(0, -1).join('.');
                const parent = pathMap.get(parentPath);

                if (parent) {
                    parent.children!.push(current);
                    // Re-sort children by order
                    parent.children!.sort((a, b) => a.order - b.order);
                } else {
                    // Orphaned or logic mismatch, treat as root
                    treeRoots.push(current);
                }
            }
        });

        return treeRoots.sort((a, b) => a.order - b.order);
    }, [structure]);


    // --- Dialog States ---
    const [nodeDialog, setNodeDialog] = useState<{ open: boolean, mode: 'add'|'edit', parentId?: string, node?: StructureNode }>({ open: false, mode: 'add' });
    const [articleDialog, setArticleDialog] = useState<{ open: boolean, mode: 'add'|'edit', parentId?: string, article?: Article }>({ open: false, mode: 'add' });

    // Forms
    const nodeForm = useForm({ type_unite: 'Titre', numero: '', titre: '' });
    const articleForm = useForm({ numero_article: '', content: '' });

    // Handlers
    const handleNodeSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        const url = nodeDialog.mode === 'add'
            ? storeNode.url(document.id)
            : updateNode.url(document.id, nodeDialog.node?.id!);

        const method = nodeDialog.mode === 'add' ? 'post' : 'put';

        // Calculate path for new node
        let path = '';
        if (nodeDialog.mode === 'add') {
             if (nodeDialog.parentId) {
                 const parent = structure.find(n => n.id === nodeDialog.parentId);
                 // We use timestamp as ID segment in path for uniqueness
                 path = parent ? `${parent.tree_path}.${Date.now()}` : `root.${Date.now()}`;
             } else {
                 path = `root.${Date.now()}`;
             }
        }

        const data = {
            ...nodeForm.data,
            ...(nodeDialog.mode === 'add' ? { tree_path: path } : {})
        };

        nodeForm.submit(method, url, {
            data,
            onSuccess: () => {
                setNodeDialog({ ...nodeDialog, open: false });
                nodeForm.reset();
            }
        });
    };

    const handleArticleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        const url = articleDialog.mode === 'add'
            ? storeArticle.url(document.id)
            : updateArticle.url(document.id, articleDialog.article?.id!);

        const method = articleDialog.mode === 'add' ? 'post' : 'put';

        articleForm.submit(method, url, {
            data: {
                ...articleForm.data,
                parent_node_id: articleDialog.parentId || articleDialog.article?.parent_id
            },
            onSuccess: () => {
                setArticleDialog({ ...articleDialog, open: false });
                articleForm.reset();
            }
        });
    }

    const handleSaveContent = (id: string, content: string) => {
        router.visit(updateArticle.url(document.id, id), {
            method: 'put',
            data: { content },
            preserveScroll: true,
        });
        // Optimistic update
        setArticles(prev => prev.map(a => a.id === id ? { ...a, content } : a));
    };

    const handleUpdateTitle = (title: string) => {
        router.patch(`/curation/${document.id}`, { title }, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const actions: TreeActions = {
        onEditNode: (node) => {
             nodeForm.setData({ type_unite: node.type_unite, numero: node.numero || '', titre: node.titre || '' });
             setNodeDialog({ open: true, mode: 'edit', node });
        },
        onDeleteNode: (id) => {
             if(confirm('Supprimer cet élément ?')) {
                 router.delete(destroyNode.url(document.id, id));
             }
        },
        onAddNode: (parentId) => {
             nodeForm.reset();
             setNodeDialog({ open: true, mode: 'add', parentId });
        },
        onAddArticle: (parentId) => {
            articleForm.reset();
             setArticleDialog({ open: true, mode: 'add', parentId });
        },
        onEditArticle: (article) => {
             articleForm.setData({ numero_article: article.numero, content: article.content });
             setArticleDialog({ open: true, mode: 'edit', article });
        },
        onDeleteArticle: (id) => {
            if(confirm('Supprimer cet article ?')) {
                 router.delete(destroyArticle.url(document.id, id));
             }
        },
        onUpdateStatus: (id, type, status) => {
            const url = type === 'node'
                ? updateNode.url(document.id, id)
                : updateArticle.url(document.id, id);

            router.visit(url, {
                 method: 'put',
                 data: { validation_status: status },
                 preserveScroll: true,
                 preserveState: true,
            });

            if (type === 'node') {
                setStructure(prev => prev.map(n => n.id === id ? { ...n, status: status as any } : n));
            } else {
                 setArticles(prev => prev.map(a => a.id === id ? { ...a, status: status as any } : a));
            }
        }
    };

    // DnD Handler
    const handleDragEnd = (event: DragEndEvent) => {
        const { active, over } = event;
        if (!over || active.id === over.id) return;

        const activeType = active.data.current?.type;
        const overType = over.data.current?.type;

        if (activeType === 'article' && overType === 'article') {
             setArticles((items) => {
                const oldIndex = items.findIndex((i) => i.id === active.id);
                const newIndex = items.findIndex((i) => i.id === over.id);
                const newOrder = arrayMove(items, oldIndex, newIndex);

                // Calculate new orders
                const updates = newOrder.map((item, index) => ({
                    id: item.id,
                    type: 'article',
                    order: index,
                    // keep parent id or update if dropped successfully across lists (not implemented fully here yet)
                    parent_id: item.parent_id
                }));

                router.post(reorder.url(document.id), { items: updates }, { preserveScroll: true });
                return newOrder.map((item, index) => ({ ...item, order: index }));
            });
        }
        // Node reordering logic can be added here
    };

    return (
        <>
            <Head title={`Curation - ${document.title}`} />

            <WorkstationLayout
                document={document}
                structure={treeStructure} // Pass the built tree
                articles={articles}
                selectedNodeId={selectedNodeId}
                selectedArticleId={selectedArticleId}
                onSelectNode={setSelectedNodeId}
                onSelectArticle={(a) => {
                    setSelectedArticleId(a.id);
                    // Also select parent node in tree if exists
                    if (a.parent_id) setSelectedNodeId(a.parent_id);
                }}
                actions={actions}
                onSaveContent={handleSaveContent}
                onUpdateTitle={handleUpdateTitle}
                onDragEnd={handleDragEnd}
            />

            {/* Node Dialog */}
            <Dialog open={nodeDialog.open} onOpenChange={(open) => setNodeDialog(prev => ({ ...prev, open }))}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{nodeDialog.mode === 'add' ? 'Ajouter un élément' : 'Modifier élément'}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleNodeSubmit} className="space-y-4">
                        <div className="space-y-2">
                            <Label>Type d'unité</Label>
                            <Select
                                value={nodeForm.data.type_unite}
                                onValueChange={(val) => nodeForm.setData('type_unite', val)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Choisir..." />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Livre">Livre</SelectItem>
                                    <SelectItem value="Titre">Titre</SelectItem>
                                    <SelectItem value="Chapitre">Chapitre</SelectItem>
                                    <SelectItem value="Section">Section</SelectItem>
                                    <SelectItem value="Paragraphe">Paragraphe</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-2">
                            <Label>Numéro (ex: I, 1, A)</Label>
                            <Input
                                value={nodeForm.data.numero}
                                onChange={e => nodeForm.setData('numero', e.target.value)}
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>Titre (optionnel)</Label>
                            <Input
                                value={nodeForm.data.titre}
                                onChange={e => nodeForm.setData('titre', e.target.value)}
                            />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setNodeDialog({ ...nodeDialog, open: false })}>
                                Annuler
                            </Button>
                            <Button type="submit" disabled={nodeForm.processing}>
                                Enregistrer
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Article Dialog */}
            <Dialog open={articleDialog.open} onOpenChange={(open) => setArticleDialog(prev => ({ ...prev, open }))}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{articleDialog.mode === 'add' ? 'Ajouter article' : 'Modifier article'}</DialogTitle>
                    </DialogHeader>
                     <form onSubmit={handleArticleSubmit} className="space-y-4">
                        <div className="space-y-2">
                            <Label>Numéro Article</Label>
                            <Input
                                value={articleForm.data.numero_article}
                                onChange={e => articleForm.setData('numero_article', e.target.value)}
                                placeholder="ex: 12, 12 bis..."
                            />
                        </div>
                        {/* Only show content field on creation, editing happens in main view mostly */}
                        {articleDialog.mode === 'add' && (
                             <div className="space-y-2">
                                <Label>Contenu initial</Label>
                                <Input
                                    value={articleForm.data.content}
                                    onChange={e => articleForm.setData('content', e.target.value)}
                                />
                            </div>
                        )}
                         <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setArticleDialog({ ...articleDialog, open: false })}>
                                Annuler
                            </Button>
                            <Button type="submit" disabled={articleForm.processing}>
                                Enregistrer
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
