import { useState, useRef, useEffect } from 'react';
import { Input } from '@/components/ui/input';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { Pencil } from 'lucide-react';
import { cn } from '@/lib/utils';

interface EditableHeaderTitleProps {
    title: string;
    onUpdate: (newTitle: string) => void;
    className?: string;
}

export default function EditableHeaderTitle({ title, onUpdate, className }: EditableHeaderTitleProps) {
    const [isEditing, setIsEditing] = useState(false);
    const [tempTitle, setTempTitle] = useState(title);
    const inputRef = useRef<HTMLInputElement>(null);

    // Sync state if prop changes (e.g. external update)
    useEffect(() => {
        setTempTitle(title);
    }, [title]);

    useEffect(() => {
        if (isEditing && inputRef.current) {
            inputRef.current.focus();
        }
    }, [isEditing]);

    const handleSave = () => {
        if (tempTitle.trim() !== title) {
            onUpdate(tempTitle);
        }
        setIsEditing(false);
    };

    const handleKeyDown = (e: React.KeyboardEvent) => {
        if (e.key === 'Enter') {
            handleSave();
        } else if (e.key === 'Escape') {
            setTempTitle(title);
            setIsEditing(false);
        }
    };

    if (isEditing) {
        return (
            <Input
                ref={inputRef}
                value={tempTitle}
                onChange={(e) => setTempTitle(e.target.value)}
                onBlur={handleSave}
                onKeyDown={handleKeyDown}
                className={cn("h-8 px-2 py-1 text-lg font-semibold min-w-[300px]", className)}
            />
        );
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <div
                    onClick={() => setIsEditing(true)}
                    className={cn(
                        "group flex items-center gap-2 cursor-pointer rounded px-1.5 py-0.5 -ml-1.5 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors max-w-full overflow-hidden",
                        className
                    )}
                >
                    <span className="truncate">{title}</span>
                    <Pencil className="h-3.5 w-3.5 text-zinc-400 opacity-0 group-hover:opacity-100 transition-opacity shrink-0" />
                </div>
            </TooltipTrigger>
            <TooltipContent side="bottom" align="start" className="max-w-[400px] break-words">
                <p>{title}</p>
                <p className="text-[10px] text-zinc-400 mt-1">Cliquez pour modifier</p>
            </TooltipContent>
        </Tooltip>
    );
}
