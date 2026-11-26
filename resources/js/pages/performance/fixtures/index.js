/**
 * Shared Dashboard fixtures (FIX-02-FE-01, FIX-06-FE-01, FIX-08-FE-01) for building components before the endpoints exist.
 * Swap them for the API response once the backend is integrated.
 */

export { completeCoverage, emptyDayCoverage, incompleteCoverage, platforms } from './coverage';
export { transactions } from './transactions';
export { emptyCompleteSummary, incompleteSummary, missingComparisonSummary, todaySummary } from './summary';
export { hourlyTooLongError, todayDailyTrend, todayHourlyTrend } from './trend';
export {
    last7DaysDownloaders,
    todayDailyDownloaders,
    todayHourlyDownloaders,
    todayMissingCutoffComparisonDownloaders,
    yesterdayDailyBeforeRevisionDownloaders,
    yesterdayDailyDownloaders,
    yesterdayHourlyDownloaders,
} from './downloader';
