import Alpine from 'alpinejs';
import promoAnalysis from './analysis';
import promoAnalysisActions from './analysis-actions';
import promoAnalysisCode from './analysis-code';
import promoAnalysisCodeUsage from './analysis-code-usage';
import promoAnalysisFunnel from './analysis-funnel';
import promoAnalysisProductType from './analysis-product-type';
import promoAnalysisSource from './analysis-source';
import promoAnalysisSummary from './analysis-summary';
import promoAnalysisTopCodes from './analysis-top-codes';
import promoAnalysisTrend from './analysis-trend';
import promoCodeBulkEntry from './bulk-entry';
import promoCodeManagement from './promo-code';
import promoRowMenu from './row-menu';
import { promoMasterTable, promoPlatformTable } from './table';
import promoTabs from './tabs';
import promoUnregisteredCodes from './unregistered';

// "promo-codes" stays the key of Kelola Kode, so links from the unregistered code alert (?tab=promo-codes&code=…) keep working.
Alpine.data('promoManagement', () => promoTabs('promo', ['analysis', 'promo-codes', 'master-data'], 'tab'));
Alpine.data('promoTabs', promoTabs);
Alpine.data('promoRowMenu', promoRowMenu);

Alpine.data('promoAnalysis', promoAnalysis);
Alpine.data('promoAnalysisActions', promoAnalysisActions);
Alpine.data('promoAnalysisSummary', promoAnalysisSummary);
Alpine.data('promoAnalysisTrend', promoAnalysisTrend);
Alpine.data('promoAnalysisTopCodes', promoAnalysisTopCodes);
Alpine.data('promoAnalysisFunnel', promoAnalysisFunnel);
Alpine.data('promoAnalysisSource', promoAnalysisSource);
Alpine.data('promoAnalysisProductType', promoAnalysisProductType);
Alpine.data('promoAnalysisCode', promoAnalysisCode);
Alpine.data('promoAnalysisCodeUsage', promoAnalysisCodeUsage);
Alpine.data('promoCodeManagement', promoCodeManagement);
Alpine.data('promoCodeBulkEntry', promoCodeBulkEntry);
Alpine.data('promoUnregisteredCodes', promoUnregisteredCodes);
Alpine.data('promoMasterTable', promoMasterTable);
Alpine.data('promoPlatformTable', promoPlatformTable);
