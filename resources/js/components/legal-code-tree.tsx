import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { ChevronDown, ChevronRight, Circle } from 'lucide-react';
import { useState } from 'react';

interface CodeNode {
    id: string;
    title: string;
    status: 'active' | 'inactive' | 'modified';
    children?: CodeNode[];
}

const LEGAL_CODES: CodeNode[] = [
    {
        id: 'code-famille',
        title: 'Code de la Famille',
        status: 'active',
        children: [
            { id: 'cf-livre1', title: 'Livre I - Mariage', status: 'active', children: [
                { id: 'cf-l1-titre1', title: 'Titre I - Conditions', status: 'active' },
                { id: 'cf-l1-titre2', title: 'Titre II - Effets', status: 'modified' },
            ]},
            { id: 'cf-livre2', title: 'Livre II - Filiation', status: 'active' },
            { id: 'cf-livre3', title: 'Livre III - Obligations', status: 'inactive' },
        ],
    },
    {
        id: 'code-foncier',
        title: 'Code Foncier',
        status: 'active',
        children: [
            { id: 'cfo-livre1', title: 'Livre I - Propriété', status: 'active', children: [
                { id: 'cfo-l1-chap1', title: 'Chapitre I - Dispositions Générales', status: 'active' },
                { id: 'cfo-l1-chap2', title: 'Chapitre II - Expropriation', status: 'modified' },
            ]},
            { id: 'cfo-livre2', title: 'Livre II - Concessions', status: 'active' },
        ],
    },
    {
        id: 'code-travail',
        title: 'Code du Travail',
        status: 'active',
        children: [
            { id: 'ct-livre1', title: 'Livre I - Contrats', status: 'active' },
            { id: 'ct-livre2', title: 'Livre II - Relations Collectives', status: 'active' },
            { id: 'ct-livre3', title: 'Livre III - Sécurité et Santé', status: 'inactive' },
        ],
    },
    {
        id: 'code-penal',
        title: 'Code Pénal',
        status: 'active',
        children: [
            { id: 'cp-livre1', title: 'Livre I - Infractions', status: 'active' },
            { id: 'cp-livre2', title: 'Livre II - Peines', status: 'modified' },
        ],
    },
];

function CodeTreeNode({ node, level = 0 }: { node: CodeNode; level?: number }) {
    const [isOpen, setIsOpen] = useState(level === 0);
    const hasChildren = node.children && node.children.length > 0;

    const statusColors = {
        active: 'text-success',
        inactive: 'text-muted-foreground',
        modified: 'text-primary',
    };

    return (
        <div className="select-none">
            {hasChildren ? (
                <Collapsible open={isOpen} onOpenChange={setIsOpen}>
                    <CollapsibleTrigger asChild>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="w-full justify-start gap-2 px-2 py-1.5 h-auto font-normal hover:bg-sidebar-accent"
                            style={{ paddingLeft: `${level * 0.75 + 0.5}rem` }}
                        >
                            {isOpen ? (
                                <ChevronDown className="h-3.5 w-3.5 shrink-0" />
                            ) : (
                                <ChevronRight className="h-3.5 w-3.5 shrink-0" />
                            )}
                            <Circle
                                className={`h-2 w-2 shrink-0 fill-current ${statusColors[node.status]}`}
                            />
                            <span className="text-sm truncate">{node.title}</span>
                        </Button>
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        {node.children?.map((child) => (
                            <CodeTreeNode key={child.id} node={child} level={level + 1} />
                        ))}
                    </CollapsibleContent>
                </Collapsible>
            ) : (
                <Button
                    variant="ghost"
                    size="sm"
                    className="w-full justify-start gap-2 px-2 py-1.5 h-auto font-normal hover:bg-sidebar-accent"
                    style={{ paddingLeft: `${level * 0.75 + 0.5}rem` }}
                >
                    <Circle
                        className={`h-2 w-2 shrink-0 fill-current ${statusColors[node.status]}`}
                    />
                    <span className="text-sm truncate">{node.title}</span>
                </Button>
            )}
        </div>
    );
}

export default function LegalCodeTree() {
    return (
        <div className="h-full flex flex-col bg-sidebar border-r border-sidebar-border">
            <div className="p-4 border-b border-sidebar-border">
                <h2 className="text-sm font-semibold text-sidebar-foreground">
                    Codes Juridiques Congolais
                </h2>
            </div>
            <div className="flex-1 overflow-y-auto scrollbar-thin">
                <div className="p-2 space-y-0.5">
                    {LEGAL_CODES.map((code) => (
                        <CodeTreeNode key={code.id} node={code} />
                    ))}
                </div>
            </div>
            <div className="p-3 border-t border-sidebar-border text-xs text-muted-foreground">
                <div className="flex items-center gap-3">
                    <div className="flex items-center gap-1.5">
                        <Circle className="h-2 w-2 fill-success text-success" />
                        <span>Actif</span>
                    </div>
                    <div className="flex items-center gap-1.5">
                        <Circle className="h-2 w-2 fill-primary text-primary" />
                        <span>Modifié</span>
                    </div>
                </div>
            </div>
        </div>
    );
}
