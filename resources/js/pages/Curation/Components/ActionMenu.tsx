import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    MoreVertical,
    Plus,
    FileText,
    Pencil,
    Trash2
} from 'lucide-react';

interface ActionMenuProps {
    onEdit: () => void;
    onDelete: () => void;
    onAddChild?: () => void;
    onAddArticle?: () => void;
    type: 'node' | 'article';
}

export default function ActionMenu({ 
    onEdit, 
    onDelete, 
    onAddChild,
    onAddArticle,
    type 
}: ActionMenuProps) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild onClick={(e) => e.stopPropagation()}>
                <Button variant="ghost" size="icon" className="h-6 w-6 opacity-0 group-hover:opacity-100 transition-opacity focus:opacity-100">
                    <MoreVertical className="h-3 w-3" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuLabel>Actions</DropdownMenuLabel>
                {type === 'node' && (
                    <>
                        <DropdownMenuItem onClick={(e) => { e.stopPropagation(); onAddChild?.(); }}>
                            <Plus className="mr-2 h-4 w-4" />
                            Ajouter sous-élément
                        </DropdownMenuItem>
                         <DropdownMenuItem onClick={(e) => { e.stopPropagation(); onAddArticle?.(); }}>
                            <FileText className="mr-2 h-4 w-4" />
                            Ajouter article
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                    </>
                )}
                <DropdownMenuItem onClick={(e) => { e.stopPropagation(); onEdit(); }}>
                    <Pencil className="mr-2 h-4 w-4" />
                    Modifier
                </DropdownMenuItem>
                <DropdownMenuItem onClick={(e) => { e.stopPropagation(); onDelete(); }} className="text-red-600 focus:text-red-600">
                    <Trash2 className="mr-2 h-4 w-4" />
                    Supprimer
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
