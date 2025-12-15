import { Badge } from '@/components/ui/badge';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';

interface StatusBadgeProps {
    status: string;
    onChange?: (newStatus: string) => void;
    readOnly?: boolean;
    className?: string; // Add className support
}

export default function StatusBadge({ status, onChange, readOnly = false, className }: StatusBadgeProps) {
    const statusConfig: Record<string, { label: string; color: string; dotColor: string }> = {
        pending: {
            label: 'En attente',
            color: 'bg-zinc-100 text-zinc-600 border-zinc-200 dark:bg-zinc-800/50 dark:text-zinc-400 dark:border-zinc-700',
            dotColor: 'bg-zinc-400',
        },
        in_progress: {
            label: 'En cours',
            color: 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-900/20 dark:text-amber-500 dark:border-amber-800/30',
            dotColor: 'bg-amber-500',
        },
        validated: {
            label: 'Validé',
            color: 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-900/20 dark:text-emerald-500 dark:border-emerald-800/30',
            dotColor: 'bg-emerald-500',
        },
    };

    const current = statusConfig[status] || statusConfig.pending;

    const BadgeComponent = (
        <Badge
            variant="outline"
            className={cn(
                "gap-1.5 select-none transition-colors text-xs px-2 py-0.5 whitespace-nowrap", // Added whitespace-nowrap
                current.color,
                readOnly ? "cursor-default" : "cursor-pointer hover:shadow-sm",
                className
            )}
        >
            <div className={cn("h-1.5 w-1.5 rounded-full shrink-0", current.dotColor)} />
            {current.label}
        </Badge>
    );

    if (readOnly || !onChange) {
        return BadgeComponent;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild onClick={(e) => e.stopPropagation()}>
                {BadgeComponent}
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="min-w-[140px]">
                <DropdownMenuItem onClick={() => onChange('pending')} className="text-sm">
                    <div className="mr-2 h-2 w-2 rounded-full bg-zinc-400" />
                    En attente
                </DropdownMenuItem>
                <DropdownMenuItem onClick={() => onChange('in_progress')} className="text-sm">
                    <div className="mr-2 h-2 w-2 rounded-full bg-amber-500" />
                    En cours
                </DropdownMenuItem>
                <DropdownMenuItem onClick={() => onChange('validated')} className="text-sm">
                    <div className="mr-2 h-2 w-2 rounded-full bg-emerald-500" />
                    Validé
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
