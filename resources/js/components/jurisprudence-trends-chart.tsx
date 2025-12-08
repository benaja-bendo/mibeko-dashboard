import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, TooltipProps } from 'recharts';
import { NameType, ValueType } from 'recharts/types/component/DefaultTooltipContent';

const jurisprudenceData = [
    { year: '2020', cases: 142 },
    { year: '2021', cases: 178 },
    { year: '2022', cases: 165 },
    { year: '2023', cases: 201 },
    { year: '2024', cases: 234 },
];

function CustomTooltip({ active, payload }: TooltipProps<ValueType, NameType>) {
    if (active && payload && payload.length && payload[0]) {
        const data = payload[0].payload as { year: string; cases: number };
        return (
            <div className="bg-popover border border-border rounded-lg shadow-lg p-3">
                <p className="text-sm font-medium">{data.year}</p>
                <p className="text-xs text-muted-foreground mt-1">
                    <span className="font-semibold text-primary">{data.cases}</span> affaires
                </p>
            </div>
        );
    }
    return null;
}

export default function JurisprudenceTrendsChart() {
    return (
        <Card className="border-border">
            <CardHeader>
                <CardTitle className="text-lg">
                    Tendances de la Jurisprudence (2020-2024)
                </CardTitle>
                <p className="text-sm text-muted-foreground">
                    Volume d'affaires traitées par année
                </p>
            </CardHeader>
            <CardContent>
                <ResponsiveContainer width="100%" height={300}>
                    <BarChart
                        data={jurisprudenceData}
                        margin={{ top: 10, right: 10, left: 0, bottom: 5 }}
                    >
                        <CartesianGrid
                            strokeDasharray="3 3"
                            stroke="var(--border)"
                            opacity={0.3}
                        />
                        <XAxis
                            dataKey="year"
                            stroke="var(--muted-foreground)"
                            fontSize={12}
                            tickLine={false}
                            axisLine={{ stroke: 'var(--border)' }}
                        />
                        <YAxis
                            stroke="var(--muted-foreground)"
                            fontSize={12}
                            tickLine={false}
                            axisLine={{ stroke: 'var(--border)' }}
                        />
                        <Tooltip content={<CustomTooltip />} cursor={{ fill: 'var(--accent)' }} />
                        <Bar
                            dataKey="cases"
                            fill="var(--primary)"
                            radius={[4, 4, 0, 0]}
                            maxBarSize={60}
                        />
                    </BarChart>
                </ResponsiveContainer>
                <div className="mt-4 flex items-center justify-center gap-6 text-sm">
                    <div className="flex items-center gap-2">
                        <div className="w-3 h-3 rounded-sm bg-primary" />
                        <span className="text-muted-foreground">Affaires par année</span>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}
