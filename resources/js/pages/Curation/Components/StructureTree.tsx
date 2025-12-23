import { useState } from 'react';
import {
    ChevronRight,
    ChevronDown,
    FileText,
    Book,
    Bookmark,
    LayoutList,
    GripVertical
} from 'lucide-react';
import { cn } from '@/lib/utils';
import ActionMenu from './ActionMenu';
import {
    SortableContext,
    verticalListSortingStrategy,
    useSortable
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

// Interfaces
export interface StructureNode {
    id: string;
    type_unite: string;
    numero: string | null;
    titre: string | null;
    tree_path: string;
    status: 'pending' | 'in_progress' | 'validated';
    order: number;
    children?: StructureNode[];
}

export interface Article {
    id: string;
    numero: string;
    content: string;
    parent_id: string | null;
    order: number;
    status: 'pending' | 'in_progress' | 'validated';
}

export interface TreeActions {
    onEditNode: (node: StructureNode) => void;
    onDeleteNode: (id: string) => void;
    onAddNode: (parentId?: string) => void;
    onAddArticle: (parentId: string) => void;
    onEditArticle: (article: Article) => void;
    onDeleteArticle: (id: string) => void;
    onUpdateStatus: (id: string, type: 'node' | 'article', status: string) => void;
}

interface StructureTreeProps {
    structure: StructureNode[];
    articles: Article[];
    selectedNodeId: string | null;
    selectedArticleId: string | null;
    onSelectNode: (id: string) => void;
    onSelectArticle: (article: Article) => void;
    onSelectDocument: () => void;
    actions: TreeActions;
}

// Helpers
const getNodeIcon = (typeUnite: string) => {
    const type = typeUnite.toLowerCase();
    if (type.includes('livre') || type.includes('book')) return Book;
    if (type.includes('titre') || type.includes('title')) return Bookmark;
    if (type.includes('chapitre') || type.includes('chapter')) return LayoutList;
    return FileText;
};

const StatusDot = ({ status, onClick }: { status: string, onClick?: (s: string) => void }) => {
    const color = {
        pending: 'bg-zinc-300 dark:bg-zinc-600',
        in_progress: 'bg-amber-500',
        validated: 'bg-emerald-500',
    }[status] || 'bg-zinc-300';

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild onClick={(e) => e.stopPropagation()}>
                <div className={cn("h-2 w-2 rounded-full cursor-pointer hover:scale-125 transition-transform", color)} />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start">
                 <DropdownMenuItem onClick={() => onClick?.('pending')}>En attente</DropdownMenuItem>
                 <DropdownMenuItem onClick={() => onClick?.('in_progress')}>En cours</DropdownMenuItem>
                 <DropdownMenuItem onClick={() => onClick?.('validated')}>Validé</DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
};

// Sortable Components
const SortableNode = ({
    node,
    level,
    onSelectNode,
    selectedNodeId,
    selectedArticleId,
    articles,
    onSelectArticle,
    actions
}: {
    node: StructureNode;
    level: number;
    onSelectNode: (id: string) => void;
    selectedNodeId: string | null;
    selectedArticleId: string | null;
    articles: Article[];
    onSelectArticle: (article: Article) => void;
    actions: TreeActions;
}) => {
    const [expanded, setExpanded] = useState(true);
    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging
    } = useSortable({ id: node.id, data: { type: 'node', parentId: null } });

    const style = {
        transform: CSS.Transform.toString(transform),
        transition,
        opacity: isDragging ? 0.5 : 1,
    };

    const nodeArticles = articles.filter(a => a.parent_id === node.id).sort((a,b) => a.order - b.order);
    const childrenNodes = node.children || [];

    const NodeIcon = getNodeIcon(node.type_unite);
    const isSelected = selectedNodeId === node.id;

    return (
        <div ref={setNodeRef} style={style} className="group relative">
             {/* Indentation Guide Line */}
             {level > 0 && (
                <div
                    className="absolute top-0 bottom-0 w-px bg-zinc-200 dark:bg-zinc-800"
                    style={{ left: `${(level * 16) - 9}px` }}
                />
            )}

            <div
                className={cn(
                    "flex items-center py-1.5 pr-2 cursor-pointer rounded-md text-sm transition-all relative select-none",
                    isSelected ? "bg-blue-50 dark:bg-blue-900/20" : "hover:bg-zinc-100 dark:hover:bg-zinc-800/50",
                    "my-0.5"
                )}
                style={{ paddingLeft: `${level * 16}px` }}
                onClick={() => {
                    setExpanded(!expanded);
                    onSelectNode(node.id);
                }}
            >
                {/* Drag Handle */}
                <div {...attributes} {...listeners} onClick={(e) => e.stopPropagation()} className="cursor-grab mr-0.5 text-zinc-300 hover:text-zinc-500 shrink-0 opacity-0 group-hover:opacity-100 transition-opacity w-4 flex justify-center">
                    <GripVertical className="h-3 w-3" />
                </div>

                {/* Expand Toggle */}
                <div className="mr-1 shrink-0 w-4 h-4 flex items-center justify-center rounded hover:bg-zinc-200 dark:hover:bg-zinc-700/50" onClick={(e) => { e.stopPropagation(); setExpanded(!expanded); }}>
                    {expanded ? <ChevronDown className="h-3 w-3 text-zinc-400" /> : <ChevronRight className="h-3 w-3 text-zinc-400" />}
                </div>

                {/* Icon */}
                <NodeIcon className={cn("h-4 w-4 mr-2 shrink-0", isSelected ? "text-blue-600 dark:text-blue-400" : "text-zinc-400")} />

                {/* Text */}
                <span className={cn("font-medium truncate flex-1 leading-tight text-[13px]", isSelected ? "text-blue-900 dark:text-blue-100" : "text-zinc-700 dark:text-zinc-300")}>
                    <span className="font-semibold">{node.type_unite}</span>
                    {node.numero && <span className="ml-1.5 font-semibold">{node.numero}</span>}
                    {node.titre && <span className="text-zinc-500 dark:text-zinc-500 font-normal ml-2">{node.titre}</span>}
                </span>

                {/* Actions */}
                <div className="flex items-center gap-2 shrink-0 ml-2 opacity-0 group-hover:opacity-100 transition-opacity">
                    <StatusDot
                        status={node.status || 'pending'}
                        onClick={(s) => actions.onUpdateStatus(node.id, 'node', s)}
                    />
                    <ActionMenu
                        onEdit={() => actions.onEditNode(node)}
                        onDelete={() => actions.onDeleteNode(node.id)}
                        onAddChild={() => actions.onAddNode(node.id)}
                        onAddArticle={() => actions.onAddArticle(node.id)}
                        type="node"
                    />
                </div>
            </div>

            {expanded && (
                <div>
                     <SortableContext
                        items={childrenNodes.map(c => c.id)}
                        strategy={verticalListSortingStrategy}
                     >
                        {childrenNodes.sort((a,b) => a.order - b.order).map(child => (
                            <SortableNode
                                key={child.id}
                                node={child}
                                level={level + 1}
                                onSelectNode={onSelectNode}
                                selectedNodeId={selectedNodeId}
                                selectedArticleId={selectedArticleId}
                                articles={articles}
                                onSelectArticle={onSelectArticle}
                                actions={actions}
                            />
                        ))}
                    </SortableContext>

                    <SortableContext
                        items={nodeArticles.map(a => a.id)}
                        strategy={verticalListSortingStrategy}
                    >
                        {nodeArticles.map(article => (
                            <SortableArticle
                                key={article.id}
                                article={article}
                                level={level + 1}
                                selectedArticleId={selectedArticleId}
                                onSelectArticle={onSelectArticle}
                                actions={actions}
                            />
                        ))}
                    </SortableContext>
                </div>
            )}
        </div>
    );
};

const SortableArticle = ({
    article,
    level,
    selectedArticleId,
    onSelectArticle,
    actions
}: {
    article: Article;
    level: number;
    selectedArticleId: string | null;
    onSelectArticle: (article: Article) => void;
    actions: TreeActions;
}) => {
    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging
    } = useSortable({ id: article.id, data: { type: 'article', parentId: article.parent_id } });

    const style = {
        transform: CSS.Transform.toString(transform),
        transition,
        opacity: isDragging ? 0.5 : 1,
    };

    const isSelected = selectedArticleId === article.id;

    return (
        <div ref={setNodeRef} style={style} className="group relative">
            {/* Indentation Guide Line */}
            <div
                className="absolute top-0 bottom-0 w-px bg-zinc-200 dark:bg-zinc-800"
                style={{ left: `${(level * 16) - 9}px` }}
            />

            <div
                className={cn(
                    "flex items-center py-1.5 pr-2 cursor-pointer rounded-md text-sm transition-all relative select-none",
                    isSelected ? "bg-blue-50 dark:bg-blue-900/20" : "hover:bg-zinc-100 dark:hover:bg-zinc-800/50",
                    "my-0.5"
                )}
                style={{ paddingLeft: `${level * 16}px` }}
                onClick={() => onSelectArticle(article)}
            >
                 <div {...attributes} {...listeners} onClick={(e) => e.stopPropagation()} className="cursor-grab mr-0.5 text-zinc-300 hover:text-zinc-500 shrink-0 opacity-0 group-hover:opacity-100 transition-opacity w-4 flex justify-center">
                    <GripVertical className="h-3 w-3" />
                </div>

                <div className="mr-1 w-4 flex justify-center">
                     {/* Spacer to align with nodes */}
                </div>

                <FileText className={cn("h-3.5 w-3.5 mr-2 shrink-0", isSelected ? "text-blue-500" : "text-zinc-400")} />

                <span className={cn("truncate flex-1 text-[13px]", isSelected ? "font-medium text-blue-900 dark:text-blue-100" : "text-zinc-600 dark:text-zinc-400")}>
                    Article {article.numero}
                </span>

                 <div className="flex items-center gap-2 shrink-0 ml-2 opacity-0 group-hover:opacity-100 transition-opacity">
                    <StatusDot
                        status={article.status}
                        onClick={(s) => actions.onUpdateStatus(article.id, 'article', s)}
                    />
                    <ActionMenu
                        onEdit={() => actions.onEditArticle(article)}
                        onDelete={() => actions.onDeleteArticle(article.id)}
                        type="article"
                    />
                </div>
            </div>
        </div>
    );
};

export default function StructureTree({
    structure,
    articles,
    selectedNodeId,
    selectedArticleId,
    onSelectNode,
    onSelectArticle,
    onSelectDocument,
    actions
}: StructureTreeProps) {
    // Root level sorting context
    return (
        <div className="flex-1 overflow-y-auto p-2 scrollbar-thin scrollbar-thumb-zinc-200 dark:scrollbar-thumb-zinc-800">
             <div 
                className={cn(
                    "flex items-center py-1.5 px-2 mb-2 cursor-pointer rounded-md text-sm transition-all select-none",
                    !selectedNodeId && !selectedArticleId ? "bg-blue-50 dark:bg-blue-900/20 text-blue-900 dark:text-blue-100 font-semibold" : "hover:bg-zinc-100 dark:hover:bg-zinc-800/50 text-zinc-700 dark:text-zinc-400"
                )}
                onClick={onSelectDocument}
             >
                <Book className="h-4 w-4 mr-2 opacity-70" />
                Table des matières
             </div>

             <SortableContext
                items={structure.map(n => n.id)}
                strategy={verticalListSortingStrategy}
             >
                {structure.sort((a,b) => a.order - b.order).map(node => (
                    <SortableNode
                        key={node.id}
                        node={node}
                        level={0}
                        onSelectNode={onSelectNode}
                        selectedNodeId={selectedNodeId}
                        selectedArticleId={selectedArticleId}
                        articles={articles}
                        onSelectArticle={onSelectArticle}
                        actions={actions}
                    />
                ))}
            </SortableContext>
        </div>
    );
}
