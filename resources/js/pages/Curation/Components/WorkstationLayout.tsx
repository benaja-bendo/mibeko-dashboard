import { useState, useMemo } from 'react';
import { Link } from '@inertiajs/react';
import {
    ResizableHandle,
    ResizablePanel,
    ResizablePanelGroup,
} from "@/components/ui/resizable";
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { ArrowLeft, FileText } from 'lucide-react';
import { cn } from '@/lib/utils';
import { DndContext, closestCenter, KeyboardSensor, PointerSensor, useSensor, useSensors, DragEndEvent } from '@dnd-kit/core';
import { sortableKeyboardCoordinates } from '@dnd-kit/sortable';

// Components
import PdfViewer from '@/pages/Curation/Components/PdfViewer';
import StructureTree, { StructureNode, Article, TreeActions } from '@/pages/Curation/Components/StructureTree';
import ContentEditor from '@/pages/Curation/Components/ContentEditor';
import EditableHeaderTitle from '@/pages/Curation/Components/EditableHeaderTitle';

interface Document {
    id: string;
    title: string;
    source_url: string | null;
    status: string;
    date_signature: string | null;
    date_publication: string | null;
}

interface WorkstationLayoutProps {
    document: Document;
    structure: StructureNode[];
    articles: Article[];
    selectedNodeId: string | null;
    selectedArticleId: string | null;
    selectedType: 'document' | 'node' | 'article';
    onSelectDocument: () => void;
    onSelectNode: (id: string) => void;
    onSelectArticle: (article: Article) => void;
    actions: TreeActions;
    onSaveContent: (id: string, content: string) => void;
    onCreateNewVersion?: (id: string, data: { content: string; reason?: string; validFrom?: string }) => void;
    onUpdateDocument: (data: Partial<Document>) => void;
    onDragEnd: (event: DragEndEvent) => void;
}

export default function WorkstationLayout({
    document,
    structure,
    articles,
    selectedNodeId,
    selectedArticleId,
    selectedType,
    onSelectDocument,
    onSelectNode,
    onSelectArticle,
    actions,
    onSaveContent,
    onCreateNewVersion,
    onUpdateDocument,
    onDragEnd
}: WorkstationLayoutProps) {
    const [isPdfCollapsed, setIsPdfCollapsed] = useState(false);

    // Derive selected entities
    const selectedArticle = useMemo(() =>
        articles.find(a => a.id === selectedArticleId) || null
    , [articles, selectedArticleId]);

    const selectedNode = useMemo(() => {
        // Flatten tree to find node if needed, or search in props if we had flat structure.
        // workstation.tsx has flat structure as `initialStructure`. 
        // For simplicity, let's assume we can find it in the articles' parents or just search the tree.
        const findInTree = (nodes: StructureNode[], id: string): StructureNode | null => {
            for (const n of nodes) {
                if (n.id === id) return n;
                if (n.children) {
                    const found = findInTree(n.children, id);
                    if (found) return found;
                }
            }
            return null;
        };
        return selectedNodeId ? findInTree(structure, selectedNodeId) : null;
    }, [structure, selectedNodeId]);

    // Navigation logic: Find previous and next articles
    const { prevArticle, nextArticle } = useMemo(() => {
        if (!selectedArticleId) return { prevArticle: null, nextArticle: null };
        const index = articles.findIndex(a => a.id === selectedArticleId);
        return {
            prevArticle: index > 0 ? articles[index - 1] : null,
            nextArticle: index < articles.length - 1 ? articles[index + 1] : null,
        };
    }, [articles, selectedArticleId]);

    // Derive breadcrumbs
    const breadcrumbs = useMemo(() => {
        const crumbs = [{ title: document.title, type: 'Document' }];
        if (selectedArticle) {
            if (selectedArticle.parent_id) {
                const parent = structure.find(n => n.id === selectedArticle.parent_id);
                if (parent) {
                     crumbs.push({ title: `${parent.type_unite} ${parent.numero || ''}`, type: 'Structure' });
                }
            } else {
                 crumbs.push({ title: 'Non classés', type: 'Dossier' });
            }
            crumbs.push({ title: selectedArticle.numero, type: 'Article' });
        }
        return crumbs;
    }, [document.title, selectedArticle, structure]);

    // Sensors
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 8 } }),
        useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
    );

    return (
        <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
            <div className="flex h-screen flex-col overflow-hidden bg-[#F9FAFB] dark:bg-zinc-950 font-sans">
                {/* Top Header */}
                <header className="flex h-14 items-center gap-4 border-b bg-white dark:bg-zinc-900 px-6 z-10 shrink-0 shadow-sm">
                    <Link href="/curation">
                        <Button variant="ghost" size="icon" className="hover:bg-zinc-100 dark:hover:bg-zinc-800">
                            <ArrowLeft className="h-5 w-5" />
                        </Button>
                    </Link>
                    <div className="flex-1 min-w-0">
                            <div 
                                className="flex items-center gap-2.5 overflow-hidden cursor-pointer hover:opacity-80 transition-opacity"
                                onClick={onSelectDocument}
                            >
                                <FileText className="h-5 w-5 text-blue-600 dark:text-blue-500 shrink-0" />
                                <EditableHeaderTitle
                                    title={document.title}
                                    onUpdate={(title) => onUpdateDocument({ title })}
                                    className="text-lg font-semibold text-zinc-900 dark:text-zinc-100"
                                />
                                <Badge variant="secondary" className="hidden sm:inline-flex text-xs font-normal ml-2 shrink-0">
                                    {document.status}
                                </Badge>
                            </div>
                    </div>
                </header>

                <div className="flex-1 overflow-hidden">
                    <ResizablePanelGroup direction="horizontal">

                        {/* Left Group: Structure + Editor */}
                        <ResizablePanel defaultSize={60} minSize={30}>
                            <ResizablePanelGroup direction="horizontal">

                                {/* Panel B: Structure Tree */}
                                <ResizablePanel defaultSize={30} minSize={20} maxSize={40} className="bg-white dark:bg-zinc-900 border-r border-zinc-200 dark:border-zinc-800 flex flex-col">
                                     <div className="p-4 border-b border-zinc-100 dark:border-zinc-800 flex justify-between items-center bg-white dark:bg-zinc-900">
                                        <span className="text-xs font-bold tracking-wider text-zinc-500 uppercase">Structure du journal officiel</span>
                                        <Button size="icon" variant="ghost" onClick={() => actions.onAddNode()} title="Ajouter élément racine" className="h-6 w-6 p-0">
                                            +
                                        </Button>
                                     </div>
                                    <StructureTree
                                        structure={structure}
                                        articles={articles}
                                        selectedNodeId={selectedNodeId}
                                        selectedArticleId={selectedArticleId}
                                        onSelectNode={onSelectNode}
                                        onSelectArticle={onSelectArticle}
                                        onSelectDocument={onSelectDocument}
                                        actions={actions}
                                    />
                                </ResizablePanel>

                                <ResizableHandle withHandle className="bg-zinc-100 dark:bg-zinc-800 w-[1px]" />

                                {/* Panel C: Content Editor */}
                                <ResizablePanel defaultSize={70} minSize={30} className="bg-white dark:bg-zinc-900">
                                    <ContentEditor
                                        document={document}
                                        node={selectedNode}
                                        article={selectedArticle}
                                        selectedType={selectedType}
                                        prevArticle={prevArticle}
                                        nextArticle={nextArticle}
                                        onSelectArticle={onSelectArticle}
                                        breadcrumbs={breadcrumbs}
                                        onSave={onSaveContent}
                                        onCreateNewVersion={onCreateNewVersion}
                                        onUpdateStatus={(id: string, type: 'node' | 'article', status: string) => actions.onUpdateStatus(id, type, status)}
                                        onUpdateDocument={onUpdateDocument}
                                        onUpdateNode={(id: string, data: Partial<StructureNode>) => actions.onEditNode({ ...selectedNode!, ...data })} // Simplified, should probably be a direct action
                                        isPdfVisible={!isPdfCollapsed}
                                        onTogglePdf={() => setIsPdfCollapsed(!isPdfCollapsed)}
                                    />
                                </ResizablePanel>

                            </ResizablePanelGroup>
                        </ResizablePanel>

                        <ResizableHandle withHandle={!isPdfCollapsed} className={cn(isPdfCollapsed && "w-0 border-0")} />

                        {/* Panel A: PDF Viewer (Right) */}
                        <ResizablePanel
                            defaultSize={40}
                            minSize={0}
                            maxSize={50}
                            collapsible={true}
                            collapsedSize={0}
                            onCollapse={() => setIsPdfCollapsed(true)}
                            onExpand={() => setIsPdfCollapsed(false)}
                            className={cn(isPdfCollapsed && "hidden", "min-w-[0] transition-all duration-300 ease-in-out border-l border-zinc-200 dark:border-zinc-800")}
                        >
                            <PdfViewer
                                document={document}
                                collapsed={isPdfCollapsed}
                                onToggle={() => setIsPdfCollapsed(!isPdfCollapsed)}
                            />
                        </ResizablePanel>

                    </ResizablePanelGroup>
                </div>
            </div>
        </DndContext>
    );
}
