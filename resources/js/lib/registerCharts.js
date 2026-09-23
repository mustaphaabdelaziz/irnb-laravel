// Register only the Chart.js pieces the dashboard uses (tree-shakeable).
import {
    Chart,
    ArcElement,
    LineElement,
    BarElement,
    PointElement,
    CategoryScale,
    LinearScale,
    Tooltip,
    Legend,
    Filler,
    BarController,
    LineController,
    DoughnutController,
} from 'chart.js';

Chart.register(
    ArcElement,
    LineElement,
    BarElement,
    PointElement,
    CategoryScale,
    LinearScale,
    Tooltip,
    Legend,
    Filler,
    // Controllers, so a mixed chart can put a net line over income/expense
    // columns in a single canvas rather than stacking two charts.
    BarController,
    LineController,
    DoughnutController,
);
