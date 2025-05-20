import React, { useRef, useEffect, useState } from 'react';
import { calculateNiceStep } from '../utils/gameUtils';
import { colors } from '../styles/theme';
import Badge from './Badge';
import TokenIcon from './ui/TokenIcon';
import ShareModal from './ui/ShareModal';
import SharePnlButton from './ui/SharePnlButton';
import { getTokenLogo } from '../utils/tokenUtils';
import RoyaleText from './ui/RoyaleText';
import LevelBadge from './ui/LevelBadge';
import GoldenHourDrawing from './golden/GoldenHourDrawing';
import GoldenHourIndicator from './golden/GoldenHourIndicator';
// import GoldenGameIndicator from './golden/GoldenGameIndicator';
import gameService from '../services/gameService';
import RugpoolOverlay from './ui/RugpoolOverlay';
import { monitorRugpoolDrawings } from './ui/Rugpool';
import Rugpool from './ui/Rugpool'; // Import the Rugpool component

// Cache for token images
const tokenImageCache = {};

// Load Solana logo once at initialization
const solanaLogo = new Image();
solanaLogo.src = '/icons/Icon_Solana.png';

// Maximum number of candles to display before combining
const MAX_CANDLES = 32;
const MAX_OTHER_PLAYER_TRADE_MARKERS = 32; // Maximum number of other players' trade markers to display at once
const TRADE_MARKER_THROTTLE_MS = 3000; // Throttle trades to 1 per second (configurable)


// TODO: when user is < top 20, the trademarker doesnt include username in the trade.


function RugChart({ 
  miniMode,
  candles,
  price,
  trades,
  positionQty,
  avgCost,
  currentCandle,
  tickCount,
  TICKS_PER_CANDLE = 5,
  rugged,
  playerId,
  cumulativePnL = 0,
  pnlPercent = 0,
  leaderboard = [],
  allowPreRoundBuys = false,
  cooldownTimer = 0,
  gameState = {},
  isMobile = false,
  ENABLE_ANIMATIONS = true,
  autosellPrice = null, // Add new autosellPrice prop with default value of null
  tournamentData = null // Add tournament data prop
}) {
  const canvasRef = useRef(null);
  const containerRef = useRef(null);
  const leaderboardContainerRef = useRef(null); // Add a ref for the leaderboard container
  const [canvasSize, setCanvasSize] = useState({ width: 0, height: 0 });
  const [username, setUsername] = useState('');
  const [selectedCoin, setSelectedCoin] = useState(null);
  const [maxPrice, setMaxPrice] = useState(0);
  const [minPrice, setMinPrice] = useState(0);
  const [formattedPrice, setFormattedPrice] = useState('');
  const [mousePosition, setMousePosition] = useState({ x: 0, y: 0 });
  const [playerScores, setPlayerScores] = useState([]);
  const [isLeaderboardScrolled, setIsLeaderboardScrolled] = useState(false);
  const [goldenOutlineAnimationFrame, setGoldenOutlineAnimationFrame] = useState(0);
  const [goldenFlakeAnimationFrame, setGoldenFlakeAnimationFrame] = useState(0);
  const [flakesInitialized, setFlakesInitialized] = useState(false);
  const goldFlakeCanvasRef = useRef(null); // Add ref for the gold flake canvas
  
  // Store the filtered non-player trade IDs to maintain consistency between renders
  const displayedTradeIdsRef = useRef([]);
  
  // Add state to track animated trades and currently animating trades
  const [animatedTradeIds, setAnimatedTradeIds] = useState(new Set());
  const [animatingTrades, setAnimatingTrades] = useState([]);
  
  // Add these refs to store animation state outside of React's rendering cycle
  const animatingTradesRef = useRef([]);
  const needsRenderRef = useRef(false);
  
  // Add flag to track whether this is the initial load of trades
  const isInitialLoadRef = useRef(true);
  
  // Animation frame ref for trade animations
  const tradeAnimationFrameRef = useRef(null);
  
  // Use a single ref to store the trades we've decided to show, grouped by second
  const visibleTradesBySecondRef = useRef({});
  
  // Function to generate a unique ID for a trade based on its properties
  const generateTradeId = (trade) => {
    // Combine relevant properties to create a unique identifier
    return `${trade.playerId}-${trade.tickIndex}-${trade.price}-${trade.type}-${trade.coinAddress}`;
  };
  
  // Animation-related state and refs
  const [isAnimating, setIsAnimating] = useState(false);
  const [overlayOpacity, setOverlayOpacity] = useState(allowPreRoundBuys ? 0.5 : 0);
  const prevCandlesRef = useRef([]);
  const prevPriceRef = useRef(null);
  const prevCurrentCandleRef = useRef(null);
  const animationStartTimeRef = useRef(null);
  const animationFrameRef = useRef(null);
  const ANIMATION_DURATION = 150; // 150ms animation duration
  
  // Add ruggedRef to track previous rugged state
  const ruggedRef = useRef(false);
  
  // Add a ref to store accumulated candles over time
  const accumulatedCandlesRef = useRef([]);
  
  // Add separate ref for storing last rugged game data
  const lastRuggedDataRef = useRef({
    username: '',
    cumulativePnL: 0,
    pnlPercent: 0,
    selectedCoin: null,
    candles: [],
    trades: [],
    positionQty: 0,
    hasData: false
  });
  
  // Add state for share modal and storing rugged game data
  const [showShareModal, setShowShareModal] = useState(false);
  const [shareData, setShareData] = useState({
    username: '',
    cumulativePnL: 0,
    pnlPercent: 0,
    selectedCoin: null,
    candles: [],
    trades: [],
    positionQty: 0,
    hasData: false
  });

  // Add state for animation toggle (default to the prop value)
  const [animationsEnabled, setAnimationsEnabled] = useState(ENABLE_ANIMATIONS);
  
  // Add state for Golden Hour
  const [goldenHourDrawing, setGoldenHourDrawing] = useState(null);
  const [showGoldenHourDrawing, setShowGoldenHourDrawing] = useState(false);
  const [goldenHourState, setGoldenHourState] = useState(null);
  
  // Add state for Rug Royale tournament
  const [rugRoyaleState, setRugRoyaleState] = useState(null);
  const [rugRoyaleAnimationFrame, setRugRoyaleAnimationFrame] = useState(0);
  const [tournamentTimeRemaining, setTournamentTimeRemaining] = useState(null);
  const [isFinalRound, setIsFinalRound] = useState(false);
  const [isPriceInUpperArea, setIsPriceInUpperArea] = useState(false); // Add state for price position
  // Add ref to store the Rug Royale animation ID
  const royaleAnimationIdRef = useRef(null);
  
  // Track round changes - preserve rugged data for later access
  useEffect(() => {
    // Simple round change detection - if we go from rugged to not rugged, that's a new round
    if (ruggedRef.current && !rugged) {
      console.log('NEW ROUND DETECTED - PRESERVING RUGGED DATA');
      // Do NOT reset the lastRuggedDataRef here - we want to keep this data
      
      // Only mark the shareData as needing refresh if the modal is closed
      if (!showShareModal) {
        setShareData(prev => ({...prev, hasData: false}));
      }
      
      // Clear accumulated candles when a new round starts
      accumulatedCandlesRef.current = [];
    }
    // Update ref for comparison in next effect run
    ruggedRef.current = rugged;
  }, [rugged, showShareModal]);

  // Accumulate candles from light game state updates
  useEffect(() => {
    if (candles.length > 0 && !allowPreRoundBuys) {
      // For full state updates (like initial load or refresh during a game),
      // we need to detect if we're receiving a full game state (which has a lot of candles)
      const isFullGameState = candles.length >= 10; // Assuming full state has 10+ candles
      
      // For the first update, after a new round, or when receiving a full game state on refresh
      if (accumulatedCandlesRef.current.length === 0 || isFullGameState) {
        console.log(`Initializing with ${candles.length} candles (full state: ${isFullGameState})`);
        accumulatedCandlesRef.current = [...candles];
        return;
      }

      // Special handling for rug event - ensure we capture the final candle
      if (rugged && currentCandle) {
        // Check if the current candle is already in our accumulated candles
        const lastAccumulated = accumulatedCandlesRef.current[accumulatedCandlesRef.current.length - 1];
        const isFinalCandleAdded = lastAccumulated && 
          Math.abs(lastAccumulated.close - currentCandle.close) < 0.0001 &&
          Math.abs(lastAccumulated.high - currentCandle.high) < 0.0001 &&
          Math.abs(lastAccumulated.low - currentCandle.low) < 0.0001;
          
        // If the final rug candle isn't in our accumulated candles, add it
        if (!isFinalCandleAdded) {
          console.log('Adding final rug candle to accumulated candles');
          accumulatedCandlesRef.current = [...accumulatedCandlesRef.current, { ...currentCandle }];
        }
        return;
      }
      
      // Don't accumulate if we're rugged (and already handled above) - preserve what we have
      if (rugged) return;

      // For subsequent light updates, merge new candles with existing ones
      const existingCandles = accumulatedCandlesRef.current;
      const newCandles = candles;
      
      // If we already have candles, we need to identify new ones to add
      // We'll identify them based on their properties
      
      if (newCandles.length > 0 && existingCandles.length > 0) {
        // Assume the newest candle in existingCandles corresponds to the oldest in newCandles
        // This would usually be the case for a sliding window of recent candles
        const lastExistingCandle = existingCandles[existingCandles.length - 1];
        let matchFound = false;
        
        // Try to find where the new candles sequence starts in relation to existing candles
        for (let i = 0; i < newCandles.length; i++) {
          const newCandle = newCandles[i];
          
          // Simple matching based on OHLC values - could be improved with timestamps if available
          if (Math.abs(newCandle.open - lastExistingCandle.open) < 0.0001 &&
              Math.abs(newCandle.high - lastExistingCandle.high) < 0.0001 &&
              Math.abs(newCandle.low - lastExistingCandle.low) < 0.0001 &&
              Math.abs(newCandle.close - lastExistingCandle.close) < 0.0001) {
            // Found a matching candle - add all subsequent candles
            accumulatedCandlesRef.current = [
              ...existingCandles,
              ...newCandles.slice(i + 1)
            ];
            matchFound = true;
            break;
          }
        }
        
        // If no exact match found, assume the first candle in newCandles is next after the last existing candle
        if (!matchFound) {
          accumulatedCandlesRef.current = [...existingCandles, ...newCandles];
        }
      }
    } else if (allowPreRoundBuys) {
      // Reset accumulated candles in pre-round phase
      accumulatedCandlesRef.current = [];
    }
  }, [candles, rugged, allowPreRoundBuys, currentCandle]);

  // Capture game data when rugged
  useEffect(() => {
    if (rugged && candles.length > 0) {
      // console.log('GAME RUGGED - CAPTURING DATA FOR SHARING - CANDLES:', candles.length);
      
      // When game rugs, ensure we're using accumulated candles, not just the recent ones
      // If we somehow don't have accumulated candles, fallback to current candles
      const ruggedCandles = accumulatedCandlesRef.current.length > 0 ? 
        [...accumulatedCandlesRef.current] : 
        [...candles];
      
      // Create a complete data snapshot of the rugged state
      const ruggedData = {
        username,
        cumulativePnL,
        pnlPercent,
        selectedCoin,
        // Use accumulated candles instead of just current candles
        candles: ruggedCandles,
        trades: [...trades],
        positionQty,
        hasData: true
      };
      
      // Save to both state and ref to ensure persistence
      setShareData(ruggedData);
      
      // Save to ref for later use
      lastRuggedDataRef.current = {
        ...ruggedData,
        candles: [...ruggedCandles]  // Ensure we have a deep copy of candles
      };
    }
  }, [rugged, username, cumulativePnL, pnlPercent, selectedCoin, candles, trades, positionQty]);

  // Handle modal open/close
  const handleOpenShareModal = () => {
    console.log('OPENING SHARE MODAL');
    
    // Check if we need to restore from lastRuggedDataRef
    if (!shareData.hasData && lastRuggedDataRef.current.hasData) {
      console.log('RESTORING DATA FROM LAST RUGGED STATE - CANDLES:', lastRuggedDataRef.current.candles.length);
      
      // Restore from lastRuggedDataRef
      setShareData({
        ...lastRuggedDataRef.current,
        hasData: true
      });
    } else if (rugged) {
      // We're in a rugged state, but don't have data yet
      console.log('USING CURRENT RUGGED STATE DATA - CANDLES:', candles.length);
      
      // Use accumulated candles for share modal, falling back to current candles
      const candlesToUse = accumulatedCandlesRef.current.length > 0 ? 
        [...accumulatedCandlesRef.current] : 
        [...candles];
      
      setShareData({
        username,
        cumulativePnL,
        pnlPercent,
        selectedCoin,
        candles: candlesToUse,
        trades: [...trades],
        positionQty,
        hasData: true
      });
    }
    
    setShowShareModal(true);
  };

  const handleCloseShareModal = () => {
    setShowShareModal(false);
  };

  // Preload token images for all tokens in trades and leaderboard
  useEffect(() => {
    // Collect all unique token addresses
    const tokenAddresses = new Set();
    
    // Add the Solana address to our set
    const solAddress = 'So11111111111111111111111111111111111111112';
    tokenAddresses.add(solAddress);
    
    // Special case for the FREE practice token - ensure it's in our collection
    const practiceTokenAddress = "0xPractice";
    tokenAddresses.add(practiceTokenAddress);
    
    // Log any preround trades to help debug
    trades.forEach(trade => {
      if (trade.isPreRoundBuy) {
        // console.log('Found preround trade:', trade);
      }
    });
    
    // Add tokens from trades
    trades.forEach(trade => {
      if (trade.coinAddress) {
        tokenAddresses.add(trade.coinAddress);
      }
    });
    
    // Add tokens from leaderboard players
    leaderboard.forEach(player => {
      if (player.selectedCoin && player.selectedCoin.address) {
        tokenAddresses.add(player.selectedCoin.address);
      }
    });
    
    // Preload each token's image
    tokenAddresses.forEach(address => {
      if (!tokenImageCache[address]) {
        const token = { address };
        
        // Special handling for practice token
        if (address === "0xPractice") {
          const img = new Image();
          img.src = '/icons/Icon_Free_Solana.png';
          img.onload = () => {
            console.log(`Loaded FREE practice token image`);
            tokenImageCache[address] = img;
          };
          img.onerror = () => {
            console.log(`Failed to load FREE practice token image`);
            // Use Solana logo as fallback
            tokenImageCache[address] = solanaLogo;
          };
          return;
        }
        
        const logoUrl = getTokenLogo(token);
        
        if (logoUrl) {
          const img = new Image();
          img.src = logoUrl;
          img.onload = () => {
            console.log(`Loaded token image for: ${address}`);
            tokenImageCache[address] = img;
          };
          img.onerror = () => {
            console.log(`Failed to load token image for: ${address}`);
            // Use Solana logo as fallback instead of null
            tokenImageCache[address] = solanaLogo;
          };
        } else {
          // Use Solana logo if no logo URL is available
          tokenImageCache[address] = solanaLogo;
        }
      }
    });
  }, [trades, leaderboard]);
  
  // Set up resize handling
  useEffect(() => {
    const resizeCanvas = () => {
      if (!containerRef.current || !canvasRef.current) return;
      
      const canvas = canvasRef.current;
      const container = containerRef.current;
      
      // Get the device pixel ratio to handle high DPI screens properly
      const pixelRatio = window.devicePixelRatio || 1;
      
      // Get computed styles to account for padding, borders etc.
      const style = window.getComputedStyle(container);
      
      // Get container dimensions (accounting for paddings and borders)
      const displayWidth = container.clientWidth;
      const displayHeight = container.clientHeight;
      
      // Set canvas display size (CSS size)
      canvas.style.width = `${displayWidth}px`;
      canvas.style.height = `${displayHeight}px`;
      
      // Set canvas internal size accounting for device pixel ratio
      canvas.width = displayWidth * pixelRatio;
      canvas.height = displayHeight * pixelRatio;
      
      // Store the actual display size so we can use it later
      setCanvasSize({ 
        width: displayWidth,
        height: displayHeight
      });
      
      // Scale the context to account for the device pixel ratio
      const ctx = canvas.getContext('2d');
      ctx.setTransform(pixelRatio, 0, 0, pixelRatio, 0, 0);
      
      // Do NOT call drawChart here - let the state update trigger the redraw
      // through the dedicated useEffect below
    };
    
    // Initial sizing
    resizeCanvas();
    
    // Add resize listener
    window.addEventListener('resize', resizeCanvas);
    
    // Add a ResizeObserver to detect container size changes even when window size doesn't change
    // This helps with initial rendering when the component mounts
    if (window.ResizeObserver) {
      const observer = new ResizeObserver(() => {
        resizeCanvas();
      });
      
      if (containerRef.current) {
        observer.observe(containerRef.current);
      }
      
      return () => {
        observer.disconnect();
        window.removeEventListener('resize', resizeCanvas);
      };
    }
    
    return () => {
      window.removeEventListener('resize', resizeCanvas);
    };
  }, []);
  
  // Find the current player's data
  useEffect(() => {
    if (playerId && leaderboard && leaderboard.length > 0) {
      const currentPlayer = leaderboard.find(player => player.id === playerId);
      if (currentPlayer) {
        setUsername(currentPlayer.username || 'Player');
        setSelectedCoin(currentPlayer.selectedCoin);
      }
    }
  }, [playerId, leaderboard]);
  
  // Add ref to track previous preround state
  const prevPreRoundRef = useRef(null);
  
  // Add effect to handle transition between preround and regular game modes
  // to prevent leaderboard entry sticking
  useEffect(() => {
    // Check if this is an actual transition from preround to regular
    const wasPreRound = prevPreRoundRef.current;
    const isTransitioning = wasPreRound === true && !allowPreRoundBuys;
    
    // Update the ref for next comparison
    prevPreRoundRef.current = allowPreRoundBuys;
    
    // Reset animated trade tracking regardless of transition
    setAnimatedTradeIds(new Set());
    
    // Only execute transition code when actually transitioning modes
    if (isTransitioning) {
      // Log transition for debugging
      console.log('Transitioning from preround to regular game - refreshing leaderboard');
      
      // This helps force the leaderboard to completely re-render and use new sorting logic
      // Set a small timeout to ensure this runs after the game state update from the server
      setTimeout(() => {
        // Filter the leaderboard entries to remove stale data
        const filteredLeaderboard = leaderboard
          .filter(player => player && player.id) // Keep only valid entries
          .map(player => {
            // Reset preround-specific values if missing gaming round data
            if (typeof player.pnl !== 'number' || typeof player.pnlPercent !== 'number') {
              return {
                ...player,
                pnl: 0,
                pnlPercent: 0
              };
            }
            return player;
          });
        
        // Set player scores to force re-render of sorted leaderboard
        setPlayerScores(filteredLeaderboard);
      }, 100);
    }
  }, [allowPreRoundBuys, leaderboard]);
  
  // Track changes in allowPreRoundBuys to handle overlay fade-out
  useEffect(() => {
    if (!allowPreRoundBuys) {
      // Round is starting, fade out the overlay
      let startTime;
      const fadeOutDuration = 500; // 500ms fade-out duration
      
      const fadeOut = (timestamp) => {
        if (!startTime) startTime = timestamp;
        const elapsed = timestamp - startTime;
        const progress = Math.min(elapsed / fadeOutDuration, 1);
        
        setOverlayOpacity(0.5 * (1 - progress)); // Start from 0.5 (50%) opacity
        
        if (progress < 1) {
          requestAnimationFrame(fadeOut);
        } else {
          setOverlayOpacity(0); // Ensure it ends at 0
        }
      };
      
      requestAnimationFrame(fadeOut);
    } else {
      // Pre-round is active, set overlay opacity to 50%
      setOverlayOpacity(0.5);
    }
  }, [allowPreRoundBuys]);
  
  // Function to process a new trade (from socket) and add it to animation queue if appropriate
  const processNewTrade = (trade) => {
    // Skip if it's the current player's trade
    if (trade.playerId === playerId) return;
    
    // Skip if we're in pre-round or rugged state
    if (allowPreRoundBuys || rugged) return;
    
    // Generate unique ID for this trade
    const tradeId = generateTradeId(trade);
    
    // Skip if we've already processed this trade ID
    if (animatedTradeIds.has(tradeId)) return;
    
    // Mark this trade as processed regardless of whether it's shown
    setAnimatedTradeIds(prev => {
      const updated = new Set(prev);
      updated.add(tradeId);
      return updated;
    });
    
    // Add timestamp to trade
    const now = Date.now();
    const secondKey = Math.floor(now / TRADE_MARKER_THROTTLE_MS);
    
    // Get trade amount - use cost for buys, proceeds for sells
    const tradeAmount = trade.type === 'buy' ? trade.cost : trade.proceeds;
    if (tradeAmount === undefined) return; // Skip if no valid amount
    
    // Compare with existing trade for this time period
    const existingTrade = visibleTradesBySecondRef.current[secondKey];
    if (existingTrade) {
      const existingAmount = existingTrade.type === 'buy' ? existingTrade.cost : existingTrade.proceeds;
      // Only replace if new trade is bigger
      if (!existingAmount || tradeAmount > existingAmount) {
        // This trade is bigger, replace the existing one
        const timestampedTrade = { ...trade, timestamp: now };
        visibleTradesBySecondRef.current[secondKey] = timestampedTrade;
        
        // Remove the old trade from animation if it's still there
        animatingTradesRef.current = animatingTradesRef.current.filter(item => 
          item.trade !== existingTrade
        );
        setAnimatingTrades(prev => prev.filter(item => 
          item.trade !== existingTrade
        ));
        
        // Add new trade to animation queue
        const newAnimatingTrade = {
          trade: timestampedTrade,
          opacity: 0,
          startTime: performance.now(),
          phase: 'fade-in'
        };
        
        // Update animation state
        animatingTradesRef.current = [...animatingTradesRef.current, newAnimatingTrade];
        setAnimatingTrades(prev => [...prev, newAnimatingTrade]);
        
        // Flag for render
        needsRenderRef.current = true;
        
        // Start animation loop if needed
        if (!tradeAnimationFrameRef.current) {
          tradeAnimationFrameRef.current = requestAnimationFrame(animateFrame);
        }
      }
    } else {
      // No existing trade for this second, make this one the representative
      const timestampedTrade = { ...trade, timestamp: now };
      visibleTradesBySecondRef.current[secondKey] = timestampedTrade;
      
      // Add to animation queue
      const newAnimatingTrade = {
        trade: timestampedTrade,
        opacity: 0,
        startTime: performance.now(),
        phase: 'fade-in'
      };
      
      // Update animation state
      animatingTradesRef.current = [...animatingTradesRef.current, newAnimatingTrade];
      setAnimatingTrades(prev => [...prev, newAnimatingTrade]);
      
      // Flag for render
      needsRenderRef.current = true;
      
      // Start animation loop if needed
      if (!tradeAnimationFrameRef.current) {
        tradeAnimationFrameRef.current = requestAnimationFrame(animateFrame);
      }
    }
  };
  
  // Cleanup old trade second keys
  useEffect(() => {
    const clearOldTimestamps = () => {
      const now = Date.now();
      const oldestValidTimestamp = now - (10 * TRADE_MARKER_THROTTLE_MS); // Keep last 10 seconds
      
      // Filter out old timestamps
      const updatedVisibleTrades = {};
      Object.entries(visibleTradesBySecondRef.current).forEach(([key, trade]) => {
        if (trade.timestamp > oldestValidTimestamp) {
          updatedVisibleTrades[key] = trade;
        }
      });
      
      visibleTradesBySecondRef.current = updatedVisibleTrades;
    };
    
    // Run cleanup every 5 seconds
    const cleanupInterval = setInterval(clearOldTimestamps, 5000);
    
    return () => clearInterval(cleanupInterval);
  }, []);
  
  // Initial load effect - set up listener for new trades from socket
  useEffect(() => {
    // Add direct listener for newTrade events
    const handleNewTrade = (tradeData) => {
      processNewTrade(tradeData);
    };
    
    // Register listener
    const unsubscribe = gameService.on('newTrade', handleNewTrade);
    
    // Clean up on unmount
    return () => {
      unsubscribe();
    };
  }, [playerId, allowPreRoundBuys, rugged]); // Dependencies that affect trade processing
  
  // Effect to detect and animate new trades from initial trades array
  useEffect(() => {
    if (!trades || trades.length === 0 || allowPreRoundBuys || rugged) return;
    
    // Mark the IDs of all existing trades as "processed" on initial load
    // to avoid animating all of them at once
    if (isInitialLoadRef.current) {
      const initialTradeIds = new Set();
      trades.forEach(trade => {
        if (trade.playerId !== playerId) {
          initialTradeIds.add(generateTradeId(trade));
        }
      });
      
      // Update the set of animated trade IDs to include all initial trades
      setAnimatedTradeIds(prev => {
        const updated = new Set(prev);
        initialTradeIds.forEach(id => updated.add(id));
        return updated;
      });
      
      // Mark initial load as complete
      isInitialLoadRef.current = false;
      return;
    }
    
    // Filter to other player trades and ones we haven't processed yet
    const otherPlayerTrades = trades.filter(trade => 
      trade.playerId !== playerId && 
      !animatedTradeIds.has(generateTradeId(trade))
    );
    
    if (otherPlayerTrades.length > 0) {
      // Create a timestamp for this batch of trades (same time for all)
      const now = Date.now();
      
      // Group trades by second, keeping the biggest trade for each second
      const groupedBySecond = {};
      
      // Process each new trade
      otherPlayerTrades.forEach(trade => {
        // Mark this trade ID as processed regardless of whether it's shown
        const tradeId = generateTradeId(trade);
        setAnimatedTradeIds(prev => {
          const updated = new Set(prev);
          updated.add(tradeId);
          return updated;
        });
        
        // Get trade amount - use cost for buys, proceeds for sells
        const tradeAmount = trade.type === 'buy' ? trade.cost : trade.proceeds;
        if (tradeAmount === undefined) return; // Skip if no valid amount
        
        const secondKey = Math.floor(now / TRADE_MARKER_THROTTLE_MS);
        
        // Check if we have an existing trade for this time period
        const existingTrade = groupedBySecond[secondKey] || visibleTradesBySecondRef.current[secondKey];
        
        if (existingTrade) {
          // Compare trade amounts
          const existingAmount = existingTrade.type === 'buy' ? existingTrade.cost : existingTrade.proceeds;
          
          // Only replace if new trade is bigger
          if (!existingAmount || tradeAmount > existingAmount) {
            groupedBySecond[secondKey] = { ...trade, timestamp: now };
          }
        } else {
          // No existing trade, use this one
          groupedBySecond[secondKey] = { ...trade, timestamp: now };
        }
      });
      
      // Find trades that need to be removed from animation (replaced by bigger trades)
      const keysToReplace = Object.keys(groupedBySecond);
      const tradesToRemove = animatingTradesRef.current.filter(item => {
        if (!item.trade || !item.trade.timestamp) return false;
        const itemKey = Math.floor(item.trade.timestamp / TRADE_MARKER_THROTTLE_MS);
        return keysToReplace.includes(itemKey.toString());
      });
      
      // Remove replaced trades from animation
      if (tradesToRemove.length > 0) {
        animatingTradesRef.current = animatingTradesRef.current.filter(item => 
          !tradesToRemove.includes(item)
        );
        setAnimatingTrades(prev => prev.filter(item => 
          !tradesToRemove.includes(item)
        ));
      }
      
      // Update our visible trades ref
      const updatedVisibleTrades = {
        ...visibleTradesBySecondRef.current,
        ...groupedBySecond
      };
      visibleTradesBySecondRef.current = updatedVisibleTrades;
      
      // Only animate the representative trades - one per second
      const newTradestoAnimate = Object.values(groupedBySecond);
      
      if (newTradestoAnimate.length > 0) {
        // Add trades to animation queue
        const newAnimatingTrades = newTradestoAnimate.map(trade => ({
          trade,
          opacity: 0,
          startTime: performance.now(),
          phase: 'fade-in'
        }));
        
        // Update animation state
        animatingTradesRef.current = [...animatingTradesRef.current, ...newAnimatingTrades];
        setAnimatingTrades(prev => [...prev, ...newAnimatingTrades]);
        
        // Flag for render
        needsRenderRef.current = true;
        
        // Start animation loop if needed
        if (!tradeAnimationFrameRef.current) {
          tradeAnimationFrameRef.current = requestAnimationFrame(animateFrame);
        }
      }
    }
  }, [trades, playerId, allowPreRoundBuys, rugged]);
  
  // Replace the useEffect for animation with this standalone function that doesn't trigger React re-renders
  const animateFrame = (timestamp) => {
    // Work with the ref directly instead of state
    const currentTrades = animatingTradesRef.current;
    const updatedTrades = [];
    let hasChanges = false;
    
    for (let i = 0; i < currentTrades.length; i++) {
      const item = currentTrades[i];
      const elapsed = timestamp - item.startTime;
      let updatedItem = null;
      
      // Fade in over 300ms (faster fade-in)
      if (item.phase === 'fade-in' && elapsed < 300) {
        updatedItem = {
          ...item,
          opacity: Math.min(1, elapsed / 300)
        };
        hasChanges = true;
      } 
      // Stay visible for 1500ms after fade-in complete
      else if (item.phase === 'fade-in' && elapsed >= 300) {
        updatedItem = {
          ...item,
          phase: 'visible',
          startTime: timestamp
        };
        hasChanges = true;
      }
      // Visible phase for 1700ms (longer visibility)
      else if (item.phase === 'visible' && elapsed < 1700) {
        updatedItem = item; // No change
      }
      // Start fade-out after visibility period
      else if (item.phase === 'visible' && elapsed >= 1700) {
        updatedItem = {
          ...item,
          phase: 'fade-out',
          startTime: timestamp
        };
        hasChanges = true;
      }
      // Fade out over 300ms (faster fade-out)
      else if (item.phase === 'fade-out' && elapsed < 300) {
        updatedItem = {
          ...item,
          opacity: Math.max(0, 1 - (elapsed / 300))
        };
        hasChanges = true;
      }
      
      // Keep trades that haven't completed fade-out
      if (updatedItem && !(item.phase === 'fade-out' && elapsed >= 300)) {
        updatedTrades.push(updatedItem);
      } else {
        hasChanges = true; // We're removing an item
      }
    }
    
    // Update the ref directly
    animatingTradesRef.current = updatedTrades;
    
    // Only update React state if trades have changed significantly (phase changes or additions/removals)
    if (hasChanges || needsRenderRef.current) {
      setAnimatingTrades([...updatedTrades]);
      needsRenderRef.current = false;
    }
    
    // Continue animation if there are still items animating
    if (updatedTrades.length > 0) {
      tradeAnimationFrameRef.current = requestAnimationFrame(animateFrame);
    } else {
      tradeAnimationFrameRef.current = null;
    }
  };
  
  // Remove the old effect that was handling animation - we're using the standalone function now
  // Effect to handle the animation lifecycle using requestAnimationFrame
  useEffect(() => {
    // Initial setup of animation frame if needed
    if (animatingTrades.length > 0 && !tradeAnimationFrameRef.current) {
      tradeAnimationFrameRef.current = requestAnimationFrame(animateFrame);
    }
    
    // Clean up animation frame on unmount
    return () => {
      if (tradeAnimationFrameRef.current) {
        cancelAnimationFrame(tradeAnimationFrameRef.current);
        tradeAnimationFrameRef.current = null;
      }
    };
  }, []); // Empty dependency array to run only once for setup/cleanup
  
  // Clean up animation frames on unmount
  useEffect(() => {
    return () => {
      if (animationFrameRef.current) {
        cancelAnimationFrame(animationFrameRef.current);
      }
      if (tradeAnimationFrameRef.current) {
        cancelAnimationFrame(tradeAnimationFrameRef.current);
      }
    };
  }, []);
  
  // Chart drawing logic
  const drawChart = (animationProgress, displayCandlesParam) => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    
    const ctx = canvas.getContext('2d');
    
    // Always use the values from state for consistent drawing
    const width = canvasSize.width;
    const height = canvasSize.height;
    
    // Ensure we have valid dimensions before drawing
    if (!width || !height) return;
    
    // Clear canvas
    ctx.clearRect(0, 0, width, height);
    
    // If Golden Hour is active, draw the golden background effect
    if (goldenHourState && goldenHourState.isActive) {
      drawGoldenHourBackground(ctx, width, height);
    }
    // If Rug Royale tournament is active, draw the tournament background effect
    else if (rugRoyaleState && rugRoyaleState.isActive) {
      drawRugRoyaleBackground(ctx, width, height);
    }
    
    // If rugged, draw special rug background
    if (rugged) {
      drawRugBackground(ctx, width, height);
    }
    
    // If we're in pre-round buys mode, draw the pre-round grid and return
    if (allowPreRoundBuys) {
      drawPreRoundGrid(ctx, width, height);
      return;
    }
    
    // If no candles, draw the initial grid
    if (accumulatedCandlesRef.current.length === 0 && !currentCandle) {
      drawInitialGrid(ctx, width, height);
      return;
    }
    
    // Use provided display candles if available, otherwise use accumulated candles
    let displayCandles = displayCandlesParam ? [...displayCandlesParam] : [...accumulatedCandlesRef.current];
    
    // Always animate the price line for smooth transitions
    let displayPrice = price;
    
    // Interpolate price if we're animating and have a previous price
    if (animationProgress !== undefined && animationProgress < 1 && prevPriceRef.current !== null) {
      displayPrice = prevPriceRef.current + (price - prevPriceRef.current) * animationProgress;
    }
    
    // If no display candles provided, build them (this shouldn't happen given our new pattern)
    if (!displayCandlesParam) {
      // Special handling for rugged state with few candles - ensure we have the final rug candle
      if (rugged && currentCandle && displayCandles.length < 10) {
        // Check if the final candle is already in our display candles
        const lastDisplayed = displayCandles.length > 0 ? displayCandles[displayCandles.length - 1] : null;
        const isFinalCandleDisplayed = lastDisplayed && 
          Math.abs(lastDisplayed.close - currentCandle.close) < 0.0001 &&
          Math.abs(lastDisplayed.high - currentCandle.high) < 0.0001 &&
          Math.abs(lastDisplayed.low - currentCandle.low) < 0.0001;
          
        // If the rug candle isn't already displayed, add it
        if (!isFinalCandleDisplayed) {
          displayCandles.push({...currentCandle, isRugCandle: true});
        }
      }
      // Add current candle if exists and we're not at a tick boundary and not a duplicate in rugged state
      else if (currentCandle && 
        (tickCount % TICKS_PER_CANDLE !== 0 || tickCount === 0) && 
        !(rugged && displayCandles.length > 0 && 
          Math.abs(displayCandles[displayCandles.length - 1].close - currentCandle.close) < 0.0001)) {
        displayCandles.push({...currentCandle});
      }
      
      // At tick boundary, add a flat candle for the next period
      if (tickCount % TICKS_PER_CANDLE === 0 && tickCount > 0 && !rugged && displayCandles.length > 0) {
        const lastPrice = displayCandles[displayCandles.length - 1].close;
        displayCandles.push({
          open: lastPrice,
          high: lastPrice,
          low: lastPrice,
          close: lastPrice
        });
      }
    }
    
    if (displayCandles.length === 0) return;

    // Combine candles if there are too many
    let processedCandles = [...displayCandles];
    let candleIndexMap = null; // Map original indices to processed indices

    if (displayCandles.length > MAX_CANDLES) {
      // Calculate how many candles to combine into one group
      const combineCount = Math.ceil(displayCandles.length / MAX_CANDLES);
      
      // Create a mapping from original indices to new indices to maintain trade markers
      candleIndexMap = new Array(displayCandles.length);
      
      // Combine candles into groups
      processedCandles = [];
      for (let i = 0; i < displayCandles.length; i += combineCount) {
        const group = displayCandles.slice(i, Math.min(i + combineCount, displayCandles.length));
        
        // Create an aggregate candle from the group
        const aggregateCandle = {
          open: group[0].open, // First candle in group for open
          close: group[group.length - 1].close, // Last candle in group for close
          high: Math.max(...group.map(c => c.high)), // Highest high
          low: Math.min(...group.map(c => c.low)), // Lowest low
          // Store original index range for reference
          originalStartIndex: i,
          originalEndIndex: Math.min(i + combineCount - 1, displayCandles.length - 1)
        };
        
        // Add opacity if any candle in the group has it
        if (group.some(c => c.opacity !== undefined)) {
          aggregateCandle.opacity = Math.max(...group.map(c => c.opacity !== undefined ? c.opacity : 1));
        }
        
        // Update the mapping - all original indices in this group map to the new index
        const newIndex = processedCandles.length;
        for (let j = i; j < Math.min(i + combineCount, displayCandles.length); j++) {
          candleIndexMap[j] = newIndex;
        }
        
        processedCandles.push(aggregateCandle);
      }
    } else {
      // No need to combine, create 1:1 mapping
      candleIndexMap = displayCandles.map((_, index) => index);
    }
    
    // VERTICAL SCALING
    let minPrice, maxPrice;
    
    // Create an array of ALL price points to consider
    const allPrices = [];
    
    // ALWAYS include the current price
    allPrices.push(displayPrice);
    
    // Add all candle prices
    processedCandles.forEach(c => {
      if (c) {
        allPrices.push(c.high, c.low, c.open, c.close);
      }
    });
    
    // Calculate true min/max
    const dataMin = Math.min(...allPrices);
    const dataMax = Math.max(...allPrices);
    
    // // Fixed scale for first few candles, but consider extreme values
    // if (displayCandles.length < 5) {
      minPrice = Math.min(0.5, dataMin * 0.9);
      maxPrice = Math.max(2.0, dataMax * 1.1);
    // } else {
    //   // Regular mode - use full data range
    //   minPrice = Math.max(0, dataMin * 0.9);
    //   maxPrice = dataMax * 1.1;
    // }
    
    // Ensure reasonable range
    if (maxPrice - minPrice < 0.1) {
      const mid = (minPrice + maxPrice) / 2;
      minPrice = Math.max(0, mid - 0.1);
      maxPrice = mid + 0.1;
    }
    
    // Draw grid lines
    drawGridLines(ctx, width, height, minPrice, maxPrice);
    
    // HORIZONTAL SCALING
    let candleWidth, startX;
    
    // Use only 75% of the width for actual chart content to avoid extending behind leaderboard
    // Reduce by additional 10px to prevent overlap
    const effectiveWidth = width * 0.71;
    
    // Standard candle width
    candleWidth = Math.min(30, Math.max(5, effectiveWidth / 50));
    
    if (processedCandles.length === 1) {
      // Start the first candle in the middle of the effective chart width
      startX = effectiveWidth / 2 - candleWidth / 2;
    } else {
      // With more candles, calculate positioning - fit everything within effective width
      const totalWidth = processedCandles.length * (candleWidth + 5);
      if (totalWidth > effectiveWidth - 20) {
        // Scale down if too many candles
        candleWidth = Math.max(1, (effectiveWidth - 20 - (processedCandles.length - 1) * 5) / processedCandles.length);
      }
      startX = (effectiveWidth - (processedCandles.length * (candleWidth + 5) - 5)) / 2;
    }
    
    // Draw each candle
    processedCandles.forEach((candle, i) => {
      const x = startX + i * (candleWidth + 5);
      
      if (!candle) return;
      
      // Calculate bar position
      const open = candle.open;
      const close = candle.close;
      const high = candle.high;
      const low = candle.low;
      
      const candleTop = close > open ? close : open;
      const candleBottom = close > open ? open : close;
      
      const yHigh = height - (high - minPrice) * (height / (maxPrice - minPrice));
      const yLow = height - (low - minPrice) * (height / (maxPrice - minPrice));
      const yTop = height - (candleTop - minPrice) * (height / (maxPrice - minPrice));
      const yBottom = height - (candleBottom - minPrice) * (height / (maxPrice - minPrice));
      
      // Apply opacity if defined (for fade-in effects)
      const opacity = candle.opacity !== undefined ? candle.opacity : 1;
      
      // Draw candle wick with opacity
      ctx.beginPath();
      ctx.strokeStyle = `rgba(136, 136, 136, ${opacity})`;
      ctx.moveTo(x + candleWidth / 2, yHigh);
      ctx.lineTo(x + candleWidth / 2, yLow);
      ctx.stroke();
      
      // Choose color based on price movement with opacity
      // Special case for rug candle - always make it red
      let fillColor;
      if (candle.isRugCandle || rugged && i === processedCandles.length - 1) {
        fillColor = `rgba(255, 0, 0, ${opacity})`;
      } else {
        fillColor = close >= open ? 
          `rgba(0, 255, 0, ${opacity})` : 
          `rgba(255, 0, 0, ${opacity})`;
      }
      ctx.fillStyle = fillColor;
      
      // Draw candle body
      const candleHeight = Math.max(1, yBottom - yTop);
      ctx.fillRect(x, yTop, candleWidth, candleHeight);
    });
    
    // Draw price markers on the left
    drawPriceMarkers(ctx, width, height, minPrice, maxPrice);
    
    // Draw autosell price line if it's set and within the visible price range
    if (autosellPrice && parseFloat(autosellPrice) >= minPrice && parseFloat(autosellPrice) <= maxPrice) {
      const autosellValue = parseFloat(autosellPrice);
      const yAutosell = height - (autosellValue - minPrice) * (height / (maxPrice - minPrice));
      
      // Draw dashed line for autosell price
      ctx.beginPath();
      ctx.strokeStyle = 'rgba(157, 78, 221, 0.8)'; // Purple color matching the autosell UI
      ctx.lineWidth = 1.5;
      ctx.setLineDash([6, 3]); // Dashed line pattern
      ctx.moveTo(0, yAutosell);
      ctx.lineTo(width, yAutosell);
      ctx.stroke();
      ctx.setLineDash([]); // Reset dash pattern
      
      // Add label for autosell price on the right side
      ctx.fillStyle = 'rgba(157, 78, 221, 1)';
      ctx.font = 'bold 12px DynaPuff, sans-serif';
      ctx.textAlign = 'right';
      // const label = `Autosell: ${autosellValue.toFixed(2)}x`;
      // ctx.fillText(label, width - 10, yAutosell - 5);
    }
    
    // Calculate price line position
    const yPrice = height - (displayPrice - minPrice) * (height / (maxPrice - minPrice));

    // Check if price is in the upper 80% of the screen and update state if needed
    const isInUpperArea = yPrice < height * 0.2;
    if (isInUpperArea !== isPriceInUpperArea) {
      setIsPriceInUpperArea(isInUpperArea);
    }

    // Draw current price line BEFORE trade markers so it appears behind them
    ctx.beginPath();
    ctx.strokeStyle = 'white';
    ctx.lineWidth = 2; // Make price line thicker
    ctx.setLineDash([5, 3]);
    ctx.moveTo(0, yPrice);
    ctx.lineTo(width, yPrice);
    ctx.stroke();
    ctx.setLineDash([]);
    ctx.lineWidth = 1; // Reset line width
    
    // ALWAYS DRAW TRADE MARKERS AFTER price line but BEFORE price label
    // Separate trades into current player's trades and other players' trades
    const currentPlayerTrades = [];
    const otherPlayerTrades = [];
    
    trades.forEach(trade => {
      const tradePlayerId = trade.playerId;
      const currentPlayerId = playerId;
      const isCurrentPlayerTrade = tradePlayerId === currentPlayerId;
      
      if (isCurrentPlayerTrade) {
        currentPlayerTrades.push(trade);
      } else {
        otherPlayerTrades.push(trade);
      }
    });
    
    // Helper function to draw a trade marker
    const drawTradeMarker = (trade, isCurrentPlayerTrade, opacity = 1) => {
      // Check if this is a FREE token trade
      const isFreeToken = trade.coinAddress === "0xPractice";
      
      // Skip FREE token trades for other players if tournament is not active or preparing
      if (isFreeToken && !isCurrentPlayerTrade && !(tournamentData?.active || tournamentData?.preparing)) {
        return;
      }
      
      // Find the candle index for this trade
      const tradeTickIndex = trade.tickIndex;
      
      // Skip trades without a valid tickIndex
      if (tradeTickIndex === undefined || tradeTickIndex === null) {
        return;
      }
      
      // Calculate candle index with fallback for invalid TICKS_PER_CANDLE
      let candleIndex = 0;
      if (TICKS_PER_CANDLE && TICKS_PER_CANDLE > 0) {
        candleIndex = Math.floor(tradeTickIndex / TICKS_PER_CANDLE);
      } else {
        // Fallback: place trades evenly across the chart
        candleIndex = Math.min(Math.floor(tradeTickIndex / 5), displayCandles.length - 1);
      }
      
      // Safety check - ensure candleIndex is valid and within display candles range
      candleIndex = Math.max(0, Math.min(candleIndex, displayCandles.length - 1));
      
      // Use candleIndexMap to directly map to the correct processed candle index
      // This ensures trades stick to their original candles even when candles are combined
      const visibleCandleIndex = candleIndexMap[candleIndex];
      
      // Calculate position based on visible candle index
      const x = startX + visibleCandleIndex * (candleWidth + 5) + candleWidth / 2;
      const y = height - (trade.price - minPrice) * (height / (maxPrice - minPrice));
      
      // Set circle size based on player
      const circleSize = isCurrentPlayerTrade ? 12 : 8;
      
      // Define colors for the rings
      const solidColor = trade.type === 'buy' ? '#00A63E' : '#E7000B';  // Full opacity
      const transparentColor = trade.type === 'buy' ? '#00A63E66' : '#E7000B66';  // 40% opacity
      
      // Special case for practice token - use yellow instead of red/green
      const isPracticeToken = isFreeToken;
      
      // Use buy/sell colors regardless of token type (for consistency with ShareModal)
      const actualSolidColor = solidColor;
      const actualTransparentColor = transparentColor;
      
      // Draw dark background circle first (larger than the marker) - matching ShareModal
      ctx.globalAlpha = opacity;
      ctx.beginPath();
      ctx.fillStyle = '#171723'; // Dark background from ShareModal
      ctx.arc(x, y, circleSize + 4, 0, Math.PI * 2);
      ctx.fill();
      
      // Draw outer circle with semi-transparent color
      ctx.beginPath();
      ctx.strokeStyle = actualTransparentColor;
      ctx.lineWidth = isCurrentPlayerTrade ? 12 : 0;  // Thicker outer ring (~6px)
      ctx.arc(x, y, circleSize, 0, Math.PI * 2);
      ctx.stroke();
      
      // Draw inner circle with solid color
      ctx.beginPath();
      ctx.strokeStyle = actualSolidColor;
      ctx.lineWidth = isCurrentPlayerTrade ? 4 : 3;  // Thinner inner ring (~2px)
      ctx.arc(x, y, circleSize, 0, Math.PI * 2);
      ctx.stroke();
      
      // Get the token information to display - use a consistent approach for all trades
      const tokenToDisplay = trade.coinAddress 
        ? { address: trade.coinAddress, ticker: trade.coinTicker || 'Unknown' }
        : { ticker: 'SOL', address: 'So11111111111111111111111111111111111111112' };
      
      // Use the token cache if available
      const cacheKey = tokenToDisplay.address;
      
      if (tokenImageCache[cacheKey] && tokenImageCache[cacheKey].complete) {
        // Draw the token icon inside the circle
        try {
          const iconSize = circleSize * 1.9; // Make icon slightly larger than circle
          
          // Create a circular clipping region for the token icon
          ctx.save();
          ctx.beginPath();
          ctx.arc(x, y, circleSize, 0, Math.PI * 2);
          ctx.clip();
          
          // Draw the image with the clip applied - better centered
          ctx.drawImage(tokenImageCache[cacheKey], x - iconSize/2, y - iconSize/2, iconSize, iconSize);
          
          // Restore the context to remove the clipping
          ctx.restore();
        } catch (e) {
          console.error(`Error drawing token icon for ${tokenToDisplay.ticker}:`, e);
          // Use Solana logo directly instead of placeholder
          if (solanaLogo.complete) {
            const iconSize = circleSize * 1.9;
            ctx.save();
            ctx.beginPath();
            ctx.arc(x, y, circleSize, 0, Math.PI * 2);
            ctx.clip();
            ctx.drawImage(solanaLogo, x - iconSize/2, y - iconSize/2, iconSize, iconSize);
            ctx.restore();
          } else {
            drawFallbackToken(ctx, x, y, circleSize, isCurrentPlayerTrade, isPracticeToken);
          }
        }
      } else {
        // Draw Solana logo directly if available instead of using placeholder
        if (solanaLogo.complete) {
          const iconSize = circleSize * 1.9;
          ctx.save();
          ctx.beginPath();
          ctx.arc(x, y, circleSize, 0, Math.PI * 2);
          ctx.clip();
          ctx.drawImage(solanaLogo, x - iconSize/2, y - iconSize/2, iconSize, iconSize);
          ctx.restore();
        } else {
          // Fallback only if Solana logo itself isn't loaded
          drawFallbackToken(ctx, x, y, circleSize, isCurrentPlayerTrade, isPracticeToken);
        }
      }
      
      // Add text for trade type and coin ticker if present
      ctx.fillStyle = isCurrentPlayerTrade ? '#ffcc00' : (isPracticeToken ? '#FFC107' : 'white');
      ctx.font = isCurrentPlayerTrade ? 'bold 11px DynaPuff, sans-serif' : '400 10px DynaPuff, sans-serif';
      let text = "";
      
      // Add "YOU" indicator for current player's trades
      if (isCurrentPlayerTrade) {
        text = isPracticeToken ? "YOU" : "YOU";
      }
      
      // Set text alignment to center for the YOU indicator
      ctx.textAlign = "center";
      // Draw text centered under the trade marker
      ctx.fillText(text, x, y + 20);
      // Reset text alignment to default for other text
      ctx.textAlign = "left";
      
      // For other players, draw the notification box if this is an animating trade
      if (!isCurrentPlayerTrade) {
        // Find the player in the leaderboard to get their username
        const player = leaderboard.find(p => p.id === trade.playerId);
        const username = player ? player.username : "Anon";
        
        // Draw notification box to the left of the marker
        const boxWidth = 95; // Reduced width for more compact look
        const boxHeight = 36; // Slightly increased height
        const boxX = Math.max(10, x - boxWidth - 8); // Position to the left with less margin
        
        // Calculate initial vertical position - MOVE DOWN BY DEFAULT
        let boxY = y - boxHeight / 2 + 5; // Centered vertically but shifted down by 5px
        
        // Ensure box doesn't get cut off at the bottom of the chart
        const bottomEdge = boxY + boxHeight;
        if (bottomEdge > height - 5) { // Keep 5px margin from bottom
          boxY = height - boxHeight - 5;
        }
        
        // Also ensure it doesn't go above the top of the chart
        if (boxY < 5) { // Keep 5px margin from top
          boxY = 5;
        }
        
        // Draw box with rounded corners
        ctx.fillStyle = 'rgba(23, 23, 35, 0.92)';
        ctx.beginPath();
        ctx.roundRect(boxX, boxY, boxWidth, boxHeight, 4); // Smaller border radius
        ctx.fill();
        
        // Create a gradient for the border that fades from bottom to top
        const borderGradient = ctx.createLinearGradient(boxX, boxY + boxHeight, boxX, boxY);
        borderGradient.addColorStop(0, actualSolidColor); // Full opacity at bottom
        borderGradient.addColorStop(0.7, actualSolidColor); // Start fading around 70%
        borderGradient.addColorStop(1, 'rgba(0, 0, 0, 0)'); // Transparent at top
        
        // Draw border with gradient
        ctx.strokeStyle = borderGradient;
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.roundRect(boxX, boxY, boxWidth, boxHeight, 4); // Smaller border radius
        ctx.stroke();
        
        // Draw connector line between marker and notification box when not aligned
        if (Math.abs(y - (boxY + boxHeight/2)) > 5) {
          ctx.beginPath();
          ctx.strokeStyle = actualSolidColor;
          ctx.moveTo(x - circleSize - 2, y);
          ctx.lineTo(boxX + boxWidth, boxY + boxHeight/2);
          ctx.stroke();
        }
        
        // Get player level (default to 1 if not available)
        const playerLevel = trade?.level || player?.level || 0;
        
        // Draw level badge icon
        const badgeSize = 12; // Size of the level badge (reduced)
        const badgeX = boxX + 6; // Left position with padding
        const badgeY = boxY + 14 - badgeSize/2; // Vertically centered with username
        
        // Draw level badge - improved version of LevelBadge component
        drawLevelBadge(ctx, badgeX, badgeY, badgeSize, playerLevel);
        
        // Truncate username if it's 10 or more characters
        const displayUsername = username.length >= 10 ? username.substring(0, 9) + '...' : username;
        
        // Draw username with smaller font - moved to the right to make room for level badge
        ctx.fillStyle = '#FFFFFF';
        ctx.font = '400 10px DynaPuff, sans-serif'; // Smaller font
        ctx.textAlign = 'left';
        ctx.textBaseline = 'middle'; // Center vertically
        ctx.fillText(displayUsername, boxX + badgeSize + 12, boxY + 14); // Fine-tuned position for badge
        
        // Format the amount nicely
        const amount = trade.type === 'buy' ? trade?.cost : trade?.proceeds || 0;
        const formattedAmount = amount.toFixed(3);
        
        // Add direction arrow based on trade type
        const directionArrow = trade.type === 'buy' ? '▲' : '▼';
        
        // Draw trade type and amount (without ticker text)
        ctx.fillStyle = actualSolidColor;
        ctx.font = '400 10px DynaPuff, sans-serif'; // Smaller font
        ctx.textBaseline = 'middle'; // Center vertically
        const tradeText = `${directionArrow} ${trade.type === 'buy' ? 'Buy' : 'Sell'} ${formattedAmount}`;
        ctx.fillText(tradeText, boxX + 6, boxY + 26); // Adjusted vertical position
        
        // Calculate position for token icon after the text - adjust to align with text
        const tradeTextWidth = ctx.measureText(tradeText).width;
        const iconSize = 12; // Smaller icon
        const iconX = boxX + 6 + tradeTextWidth + 3; // 3px padding after text
        const iconY = boxY + 26 - (iconSize/2) - 2; // Properly centered vertically with text
        
        // Draw token icon with circular clipping
        const cacheKey = tokenToDisplay.address;
        ctx.save(); // Save current context state
        
        // Create circular clipping path
        ctx.beginPath();
        const circleX = iconX + (iconSize/2);
        const circleY = iconY + (iconSize/2);
        const circleRadius = iconSize/2;
        ctx.arc(circleX, circleY, circleRadius, 0, Math.PI * 2);
        ctx.clip();
        
        // Draw the token image inside the circular clipping path
        if (tokenImageCache[cacheKey] && tokenImageCache[cacheKey].complete) {
          ctx.drawImage(tokenImageCache[cacheKey], iconX, iconY, iconSize, iconSize);
        } else if (solanaLogo.complete) {
          ctx.drawImage(solanaLogo, iconX, iconY, iconSize, iconSize);
        }
        
        ctx.restore(); // Restore context state to remove clipping
      }
      
      // Reset opacity
      ctx.globalAlpha = 1;
    };
    
    // First draw current player's trades (without animations)
    currentPlayerTrades.forEach(trade => drawTradeMarker(trade, true));
    
    // Draw animating trades for other players with current opacity
    // This is where we use the ref instead of state for the most accurate rendering
    animatingTradesRef.current.forEach(item => {
      drawTradeMarker(item.trade, false, item.opacity);
    });

    // If holding position, draw cost basis line - MOVED AFTER TRADE MARKERS
    if (positionQty > 0 && avgCost > 0) {
      const pnl = positionQty > 0 ? positionQty * (displayPrice - avgCost) : 0;
      // console.log(avgCost);
      // console.log(displayPrice);
      const pnlPercent = avgCost > 0 ? ((displayPrice - avgCost) / avgCost) * 100 : 0;
      // console.log(pnlPercent);
      
      // Use only 75% of the width for actual chart content to match the chart area
      // Reduce by additional 10px to prevent overlap
      const effectiveWidth = width * 0.71;
      
      const yCost = height - (avgCost - minPrice) * (height / (maxPrice - minPrice));
      
      ctx.beginPath();
      const inProfit = pnlPercent >= 0;
      ctx.strokeStyle = inProfit ? '#0f0' : '#f00';
      ctx.lineWidth = 2; // Make it a bit thicker for visibility
      ctx.moveTo(0, yCost);
      // Let the line extend across the full width for the fade effect
      ctx.lineTo(width, yCost);
      ctx.stroke();
      ctx.lineWidth = 1; // Reset line width
      
      // Set up fonts - use DynaPuff 400
      ctx.font = '400 12px DynaPuff, sans-serif';
      
      // Set color for multiplier based on pnlPercent
      ctx.fillStyle = inProfit ? '#0f0' : '#f00';
      
      // Get ticker from selectedCoin
      const ticker = selectedCoin && selectedCoin.address === "0xPractice" 
        ? "FREE" 
        : (selectedCoin ? selectedCoin.ticker : "SOL");
      
      // Draw the multiplier portion on the right side but within effectiveWidth
      // const multiplierText = `${avgCost.toFixed(2)}x`;
      const totalHoldings = positionQty * price;
      const multiplierText = `${totalHoldings.toFixed(3)} ${ticker} Left`;
      const textMetrics = ctx.measureText(multiplierText);
      const rightPadding = 20; // Adjusted padding to stay within chart area
      
      // Position text within the effective width
      const textX = effectiveWidth - rightPadding - textMetrics.width;
      ctx.fillText(multiplierText, textX, yCost - 8);
      
      // Draw the profit/loss amount in gray next to the multiplier
      ctx.fillStyle = '#aaa'; // Gray color for amount
      const pnlText = pnl > 0 ? `+${pnl.toFixed(3)} ${ticker}` : `${pnl.toFixed(3)} ${ticker}`;
      const pnlTextMetrics = ctx.measureText(pnlText);
      ctx.fillText(pnlText, textX - pnlTextMetrics.width - 10, yCost - 8);
    }

    // Draw price label AFTER trade markers to keep it on top
    ctx.fillStyle = 'white';
    ctx.font = '400 18px DynaPuff, sans-serif'; // Increased size from 12px to 18px
    ctx.fillText(`${displayPrice.toFixed(4)}x`, width * 0.71 - 85, yPrice - 8);

    // If rugged, draw the "Thanks for playing" text last so it's on top of everything
    if (rugged) {
      drawThanksForPlayingText(ctx, width, height);
    }
  };
  
  // Add a function to format the cooldown timer
  const formatTime = (ms) => {
    const seconds = (ms / 1000).toFixed(1);
    return `${seconds}s`;
  };

  // Add a function to format the cooldown timer for display in the React overlay
  const formatCountdownTime = (ms) => {
    // Round to nearest second and ensure it's a whole number
    const seconds = Math.max(1, Math.ceil(ms / 1000));
    return `${seconds} seconds`;
  };

  // Update the drawPreRoundGrid function to be simpler, as we'll use React components for the overlay
  function drawPreRoundGrid(ctx, width, height) {
    ctx.strokeStyle = '#444';
    ctx.fillStyle = '#aaa';
    ctx.font = '400 12px DynaPuff, sans-serif';
    
    // Draw horizontal lines at 0.5, 0.75, 1.0, 1.25, 1.5
    for (let i = 0; i <= 4; i++) {
      const price = 0.5 + (i * 0.25);
      const y = height - ((price - 0.5) / 1) * height;
      
      ctx.beginPath();
      ctx.lineWidth = price === 1.0 ? 2 : 1; // Keep 1.0 line thicker
      ctx.moveTo(0, y);
      ctx.lineTo(width, y);
      ctx.stroke();
      
      // Price labels - LEFT SIDE ONLY
      ctx.fillText(`${price.toFixed(2)}x`, 25, y - 5);
    }
    
    // Starting price marker
    const yPrice = height / 2; // 1.0 is in the middle
    ctx.beginPath();
    ctx.strokeStyle = 'white';
    ctx.setLineDash([5, 3]);
    ctx.moveTo(0, yPrice);
    ctx.lineTo(width, yPrice);
    ctx.stroke();
    ctx.setLineDash([]);
    
    // Starting price label - left side only
    ctx.fillStyle = 'white';
    ctx.fillText('1.0000x', 25, yPrice - 5);
  }

  // Add dependency on canvasSize for chart redrawing
  useEffect(() => {
    if (canvasSize.width > 0 && canvasSize.height > 0) {
      drawChart();
    }
  }, [canvasSize]);

  // Run chart drawing whenever data changes
  useEffect(() => {
    // Only run if we have valid dimensions
    if (canvasSize.width > 0 && canvasSize.height > 0) {
      // Keep track of whether something changed that requires animation
      const priceChanged = prevPriceRef.current !== null && Math.abs(price - prevPriceRef.current) > 0.0001;
      const candlesChanged = candles.length !== prevCandlesRef.current.length;
      const currentCandleChanged = 
        currentCandle !== null && prevCurrentCandleRef.current !== null &&
        Math.abs(currentCandle.close - prevCurrentCandleRef.current.close) > 0.0001;
      
      // Only animate if anything relevant changed and animations are enabled
      const shouldAnimate = 
        animationsEnabled && 
        !rugged && 
        !allowPreRoundBuys && 
        (priceChanged || candlesChanged || currentCandleChanged);
      
      if (shouldAnimate) {
        // If we're already animating, cancel the current animation
        if (animationFrameRef.current) {
          cancelAnimationFrame(animationFrameRef.current);
        }
        
        // Start animation
        setIsAnimating(true);
        animationStartTimeRef.current = performance.now();
        
        // Create an animated render state based on what's visually displayed,
        // not just what came from the backend
        let animatedCandles = [];
        const previousVisualState = prevCandlesRef.current;
        
        // DEBUG
        // console.log('Animation triggered. tickCount:', tickCount, 'boundary:', tickCount % TICKS_PER_CANDLE === 0);
        // console.log('Backend candles:', candles.length, 'Previous visual:', previousVisualState.length);
        
        // Make sure we always start from the complete previous visual state
        animatedCandles = [...previousVisualState];
        
        // Use accumulated candles for animation target
        const targetCandles = [...accumulatedCandlesRef.current];
        
        // On a tick boundary, we need to ensure the last candle's final state is properly animated
        if (tickCount % TICKS_PER_CANDLE === 0 && tickCount > 0 && !rugged && currentCandle) {
          // console.log('At candle boundary - final tick animation');
          
          // We're on a boundary - make sure the final state of the last candle is properly represented
          if (targetCandles.length > 0) {
            // Always use currentCandle for the final state at tick boundary to ensure we capture the final update
            targetCandles[targetCandles.length - 1] = { ...currentCandle };
            // console.log('Using currentCandle for final candle state');
          }
        }
        // Add current candle if not at boundary and not duplicate in rugged state
        else if (currentCandle && (tickCount % TICKS_PER_CANDLE !== 0 || tickCount === 0) && 
            !(rugged && targetCandles.length > 0 && 
              Math.abs(targetCandles[targetCandles.length - 1].close - currentCandle.close) < 0.0001)) {
          targetCandles.push({...currentCandle});
        }
        
        // Animation logic
        const animateChart = (timestamp) => {
          if (!animationStartTimeRef.current) {
            animationStartTimeRef.current = timestamp;
          }
          
          const elapsed = timestamp - animationStartTimeRef.current;
          const progress = Math.min(elapsed / ANIMATION_DURATION, 1);
          
          // Create interpolated candles for this frame
          let displayCandles = [];
          
          // Interpolate the price specifically for this animation frame
          const displayPrice = prevPriceRef.current !== null 
            ? prevPriceRef.current + (price - prevPriceRef.current) * progress
            : price;
          
          // Use targetCandles for structure, but interpolate values
          if (animatedCandles.length === 0) {
            // No previous state - use target directly
            displayCandles = [...targetCandles];
          } else if (targetCandles.length > animatedCandles.length) {
            // New candle was added or flat candle is being added at tick boundary
            // Keep previous candles unchanged
            for (let i = 0; i < animatedCandles.length; i++) {
              displayCandles.push({...animatedCandles[i]});
            }
            
            // Add any new candles with animation
            for (let i = animatedCandles.length; i < targetCandles.length; i++) {
              const newCandle = targetCandles[i];
              // Use the actual previous candle's close price or the current price reference
              const prevPrice = animatedCandles.length > 0 
                ? animatedCandles[animatedCandles.length - 1].close 
                : (prevPriceRef.current || (accumulatedCandlesRef.current.length > 0 ? accumulatedCandlesRef.current[0].open : 1.0));
              
              // For flat candles at tick boundaries, make them fade in from previous price
              const isFlatCandle = newCandle.isFlatCandle === true || 
                (newCandle.open === newCandle.close && 
                 newCandle.open === newCandle.high && 
                 newCandle.open === newCandle.low);
              
              displayCandles.push({
                open: prevPrice,
                close: prevPrice + (newCandle.close - prevPrice) * progress,
                high: prevPrice + (newCandle.high - prevPrice) * progress,
                low: prevPrice + (newCandle.low - prevPrice) * progress
              });
            }
          } else {
            // Same or fewer candles - interpolate each candle
            const maxCandles = Math.max(animatedCandles.length, targetCandles.length);
            
            for (let i = 0; i < maxCandles; i++) {
              if (i < targetCandles.length && i < animatedCandles.length) {
                // Interpolate between start and target
                const startCandle = animatedCandles[i];
                const targetCandle = targetCandles[i];
                
                displayCandles.push({
                  open: startCandle.open,
                  close: startCandle.close + (targetCandle.close - startCandle.close) * progress,
                  high: startCandle.high + (targetCandle.high - startCandle.high) * progress,
                  low: startCandle.low + (targetCandle.low - startCandle.low) * progress
                });
              } else if (i < animatedCandles.length) {
                // We had more candles before - keep them
                displayCandles.push({...animatedCandles[i]});
              } else if (i < targetCandles.length) {
                // We have more candles now - fade them in from previous price
                const targetCandle = targetCandles[i];
                // Use a previous candle, current price, or appropriate fallback for first candle
                const prevPrice = i > 0 
                  ? (targetCandles[i-1].close || displayPrice)
                  : (prevPriceRef.current || (accumulatedCandlesRef.current.length > 0 ? accumulatedCandlesRef.current[0].open : displayPrice));
                
                displayCandles.push({
                  open: prevPrice,
                  close: prevPrice + (targetCandle.close - prevPrice) * progress,
                  high: prevPrice + (targetCandle.high - prevPrice) * progress,
                  low: prevPrice + (targetCandle.low - prevPrice) * progress
                });
              }
            }
          }
          
          // Draw with our interpolated state and price
          drawChart(progress, displayCandles);
          
          if (progress < 1) {
            // Continue animation
            animationFrameRef.current = requestAnimationFrame(animateChart);
          } else {
            // Animation complete - store the FINAL VISUAL STATE, not the backend state
            setIsAnimating(false);
            animationStartTimeRef.current = null;
            
            // Store the target candles as our new visual state reference
            prevCandlesRef.current = [...targetCandles];
            prevPriceRef.current = price;
            prevCurrentCandleRef.current = currentCandle ? {...currentCandle} : null;
            
            // If we just finished animating to a candle boundary, now add the flat candle
            if (tickCount % TICKS_PER_CANDLE === 0 && tickCount > 0 && !rugged) {
              // We're at a tick boundary - AFTER the animation completes, add a flat candle
              // This creates a two-step animation - first the final candle update, then the flat candle appears
              const updatedCandles = [...prevCandlesRef.current];
              if (updatedCandles.length > 0) {
                const lastPrice = updatedCandles[updatedCandles.length - 1].close;
                
                // Create the flat candle
                const flatCandle = {
                  open: lastPrice,
                  high: lastPrice,
                  low: lastPrice,
                  close: lastPrice,
                  isFlatCandle: true
                };
                
                // Start a new animation just for the flat candle
                setIsAnimating(true);
                animationStartTimeRef.current = performance.now();
                
                // Create start and target states for flat candle animation
                const flatAnimationStartState = [...updatedCandles];
                const flatAnimationTargetState = [...updatedCandles, flatCandle];
                
                // Define a nested animation function for the flat candle
                const animateFlatCandle = (timestamp) => {
                  if (!animationStartTimeRef.current) {
                    animationStartTimeRef.current = timestamp;
                  }
                  
                  const elapsed = timestamp - animationStartTimeRef.current;
                  const progress = Math.min(elapsed / ANIMATION_DURATION, 1);
                  
                  // Build display candles - keep previous ones unchanged
                  let displayCandles = [...flatAnimationStartState];
                  
                  // Add flat candle with animation - fade it in
                  if (progress > 0) {
                    const fadeInCandle = {
                      open: flatCandle.open,
                      high: flatCandle.high,
                      low: flatCandle.low,
                      close: flatCandle.close,
                      // Use alpha/opacity to fade it in
                      opacity: progress
                    };
                    
                    displayCandles.push(fadeInCandle);
                  }
                  
                  // Draw chart with the flat candle fading in
                  drawChart(progress, displayCandles);
                  
                  if (progress < 1) {
                    // Continue animation
                    animationFrameRef.current = requestAnimationFrame(animateFlatCandle);
                  } else {
                    // Animation complete, update references
                    setIsAnimating(false);
                    animationStartTimeRef.current = null;
                    prevCandlesRef.current = flatAnimationTargetState;
                  }
                };
                
                // Start the flat candle animation
                animationFrameRef.current = requestAnimationFrame(animateFlatCandle);
              }
            }
          }
        };
        
        // Start animation loop
        animationFrameRef.current = requestAnimationFrame(animateChart);
      } else {
        // No animation needed or animations disabled, just draw
        // Build our display candles - use accumulated candles instead of just recent ones
        const displayCandles = [...accumulatedCandlesRef.current];
        
        // Add current candle if not at boundary and not already present in rugged state
        // In rugged state, the final candle is often already included in the candles array
        const shouldAddCurrentCandle = currentCandle && 
          (tickCount % TICKS_PER_CANDLE !== 0 || tickCount === 0) && 
          !(rugged && displayCandles.length > 0 && 
            Math.abs(displayCandles[displayCandles.length - 1].close - currentCandle.close) < 0.0001);
            
        if (shouldAddCurrentCandle) {
          displayCandles.push({...currentCandle});
        }
        
        // Add flat candle if at boundary and not rugged
        if (tickCount % TICKS_PER_CANDLE === 0 && tickCount > 0 && !rugged && displayCandles.length > 0) {
          const lastPrice = displayCandles[displayCandles.length - 1].close;
          displayCandles.push({
            open: lastPrice,
            high: lastPrice,
            low: lastPrice,
            close: lastPrice,
            isFlatCandle: true
          });
        }
        
        drawChart(null, displayCandles);
        
        // Store the VISUAL state for future animation reference
        prevCandlesRef.current = [...displayCandles];
        prevPriceRef.current = price;
        prevCurrentCandleRef.current = currentCandle ? {...currentCandle} : null;
      }
    }
  }, [candles, currentCandle, tickCount, rugged, allowPreRoundBuys, cooldownTimer, positionQty, price, canvasSize, animationsEnabled]);
  
  // Draw a rug background gradient
  function drawRugBackground(ctx, width, height) {
    // Create semi-transparent red gradient
    const gradient = ctx.createLinearGradient(0, 0, 0, height);
    gradient.addColorStop(0, 'rgba(139, 0, 0, 0.15)');  // More transparent
    gradient.addColorStop(1, 'rgba(255, 0, 0, 0.25)');  // More transparent
    
    // Fill background with gradient
    ctx.fillStyle = gradient;
    ctx.fillRect(0, 0, width, height);
    
    // Add "RUGGED" text with transparency
    ctx.fillStyle = 'rgba(255, 0, 0, 0.35)';  // Transparent red
    // Smaller font size on mobile
    const fontSize = isMobile ? 80 : 120;
    ctx.font = `bold ${fontSize}px DynaPuff, sans-serif`; // Updated to DynaPuff, smaller on mobile
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    
    // Draw text at an angle
    ctx.save();
    ctx.translate(width / 2, height / 2);
    ctx.rotate(-0.15); // Slight angle
    ctx.fillText('RUGGED', 0, 0);
    ctx.restore();
  }
  
  // Separate function to draw the "Thanks for playing" text
  function drawThanksForPlayingText(ctx, width, height) {
    // Add smaller subtitle with better contrast
    ctx.fillStyle = 'rgba(255, 80, 80, 0.7)';
    ctx.font = '400 24px DynaPuff, sans-serif'; // Updated to DynaPuff 400
    ctx.textAlign = 'center';
    
    // Determine text and font size based on mobile vs desktop
    const thankText = isMobile ? 'Thanks for playing 😹🫵' : 'Thanks for playing';
    const textFontSize = isMobile ? 12 : 24; // Smaller font on mobile
    
    ctx.font = `400 ${textFontSize}px DynaPuff, sans-serif`;
    
    // Calculate the width of the text
    const textMetrics = ctx.measureText(thankText);
    const textWidth = textMetrics.width;
    
    // Calculate image dimensions
    const scale = window.devicePixelRatio || 4;
    const imgWidth = 21 * 3 * scale; // Increased size for better quality
    const imgHeight = 15 * 3 * scale;
    
    // Add spacing between text and image (10px)
    const spacing = 10;
    
    // Position text baseline at the middle for better vertical alignment
    ctx.textBaseline = 'middle';
    const textY = height - 40;
    
    if (isMobile) {
      // For mobile, just draw the text with emojis included
      ctx.textAlign = 'center';
      ctx.fillStyle = 'white';
      ctx.fillText(thankText, width / 2, height - 40);
    } else {
      // For desktop, position elements separately for better control
      // Calculate total width of text + spacing + image
      const totalWidth = textWidth + spacing + (imgWidth/scale); // Adjust for screen pixels vs canvas pixels
      
      // Calculate starting x position to center the whole thing
      const startX = (width - totalWidth) / 2;
      
      // Draw text at calculated position (left-aligned now)
      ctx.textAlign = 'left'; // Change to left align for manual positioning
      ctx.fillStyle = 'white';
      ctx.fillText(thankText, startX, textY);
      
      // Add high-quality mogged.png image to the right of the text at calculated position
      const moggedImage = new Image();
      moggedImage.src = '/mogged.png'; // 😹🫵
      
      // Calculate image position (right after text + spacing)
      const imgX = startX + textWidth + spacing;
      const imgY = textY - (imgHeight/scale/2); // Align with text vertically using screen pixels
      
      // Cache the image in memory to prevent flickering on redraws
      if (!tokenImageCache['mogged']) {
        tokenImageCache['mogged'] = moggedImage;
      }
      
      // Apply image smoothing for better quality
      ctx.imageSmoothingEnabled = true;
      ctx.imageSmoothingQuality = 'high';
      
      if (moggedImage.complete) {
        // If image is already loaded, draw it with shadow for better visibility
        ctx.save();
        // Add subtle shadow
        ctx.shadowColor = 'rgba(0, 0, 0, 0.5)';
        ctx.shadowBlur = 10;
        ctx.shadowOffsetX = 2;
        ctx.shadowOffsetY = 2;
        ctx.drawImage(moggedImage, imgX, imgY, imgWidth, imgHeight);
        ctx.restore();
      }
    }
  }

  // Function to draw fallback token icon with grid pattern
  function drawFallbackToken(ctx, x, y, circleSize, isCurrentPlayerTrade, isPracticeToken) {
    // Use Solana logo as fallback instead of the grid pattern
    const iconSize = circleSize * 1.9; // Make icon slightly larger than circle
    
    // Create a circular clipping region for the token icon
    ctx.save();
    ctx.beginPath();
    ctx.arc(x, y, circleSize, 0, Math.PI * 2);
    ctx.clip();
    
    // Draw the Solana logo image if it's loaded
    if (solanaLogo.complete) {
      ctx.drawImage(solanaLogo, x - iconSize/2, y - iconSize/2, iconSize, iconSize);
    } else {
      // For practice token, use gold background
      ctx.beginPath();
      ctx.fillStyle = isPracticeToken ? '#D4AF37' : '#333'; // Gold background for practice token
      ctx.arc(x, y, circleSize - 1, 0, Math.PI * 2);
      ctx.fill();
      
      // Draw SOL text as a last resort
      ctx.fillStyle = "#fff"; // White text
      ctx.font = "bold " + (circleSize/1.5) + "px Arial";
      ctx.textAlign = "center";
      ctx.textBaseline = "middle";
      ctx.fillText("SOL", x, y);
    }
    
    ctx.restore();
    
    // For practice token, add a special "F" indicator
    if (isPracticeToken) {
      ctx.fillStyle = "#000"; // Black text
      ctx.font = "bold " + circleSize + "px Arial";
      ctx.textAlign = "center";
      ctx.textBaseline = "middle";
      ctx.fillText("F", x, y);
    }
  }
  
  // Draw a level badge based on the LevelBadge component
  function drawLevelBadge(ctx, x, y, size, level) {
    // Define badge colors and tiers based on level tiers, matching LevelBadge.jsx
    let badgeType = 'tier0'; // Default (Iron)
    
    if (level >= 100) {
      badgeType = 'tier10'; // Celestial
    } else if (level >= 90) {
      badgeType = 'tier9'; // Celestial
    } else if (level >= 80) {
      badgeType = 'tier8'; // Mythic
    } else if (level >= 70) {
      badgeType = 'tier7'; // Amethyst
    } else if (level >= 60) {
      badgeType = 'tier6'; // Emerald
    } else if (level >= 50) {
      badgeType = 'tier5'; // Diamond
    } else if (level >= 40) {
      badgeType = 'tier4'; // Platinum
    } else if (level >= 30) {
      badgeType = 'tier3'; // Gold
    } else if (level >= 20) {
      badgeType = 'tier2'; // Silver
    } else if (level >= 10) {
      badgeType = 'tier1'; // Bronze
    } else {
      badgeType = 'tier0'; // Iron
    }
    
    // Load badge image
    const badgeImage = new Image();
    badgeImage.src = `/badges/${badgeType}.png`;
    
    // Cache badges in the token image cache to avoid reloading
    const cacheKey = `badge-${badgeType}`;
    
    if (!tokenImageCache[cacheKey]) {
      tokenImageCache[cacheKey] = badgeImage;
    }
    
    // Draw the badge if it's loaded or cached
    if (tokenImageCache[cacheKey] && tokenImageCache[cacheKey].complete) {
      // Adjust badge size and position to match what user prefers
      const badgeSize = size * 1.3; // Balanced size that works with the layout
      // Position the badge more precisely to align with text
      ctx.drawImage(tokenImageCache[cacheKey], x - 1, y - 1, badgeSize, badgeSize);
    } else {
      // Fallback: draw a circle with level number if badge isn't loaded
      ctx.fillStyle = '#171723'; // Dark background
      ctx.beginPath();
      ctx.arc(x + size/2, y + size/2, size/2, 0, Math.PI * 2);
      ctx.fill();
      
      // Draw colored border based on level tier
      let borderColor;
      if (level >= 30) {
        borderColor = '#00D0FF'; // Light blue/turquoise
      } else if (level >= 20) {
        borderColor = '#FFD700'; // Gold
      } else if (level >= 10) {
        borderColor = '#C0C0C0'; // Silver
      } else {
        borderColor = '#CD7F32'; // Bronze
      }
      
      ctx.strokeStyle = borderColor;
      ctx.lineWidth = 1.5;
      ctx.beginPath();
      ctx.arc(x + size/2, y + size/2, size/2 - 1.5, 0, Math.PI * 2);
      ctx.stroke();
      
      // Draw level number
      ctx.fillStyle = borderColor;
      ctx.font = 'bold ' + (size * 0.5) + 'px DynaPuff, sans-serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      
      // If level > 99, just show 99+
      const displayLevel = level > 99 ? '99+' : level.toString();
      ctx.fillText(displayLevel, x + size/2, y + size/2);
    }
  }

  function drawGridLines(ctx, width, height, minPrice, maxPrice) {
    ctx.strokeStyle = '#444';
    ctx.fillStyle = '#aaa';
      ctx.font = '400 12px DynaPuff, sans-serif';
      
    // Calculate step size for grid lines
    const step = calculateNiceStep(minPrice, maxPrice);
    const firstGrid = Math.ceil(minPrice / step) * step;
    
    // Draw horizontal grid lines
    for (let price = firstGrid; price <= maxPrice; price += step) {
      const y = height - (price - minPrice) * (height / (maxPrice - minPrice));
      
      ctx.beginPath();
      // Make all grid lines solid
      ctx.lineWidth = price === 1.0 ? 2 : 1; // Keep 1.0 line thicker
      ctx.moveTo(0, y);
      // Draw lines across full width to extend under the gradient
      ctx.lineTo(width, y);
      ctx.stroke();
    }
  }
  
  function drawPriceMarkers(ctx, width, height, minPrice, maxPrice) {
    ctx.fillStyle = '#aaa';
    ctx.font = '400 12px DynaPuff, sans-serif'; // Updated to DynaPuff 400
    
    // Draw price markers on the left only
    const priceStep = calculateNiceStep(minPrice, maxPrice);
    const firstGrid = Math.ceil(minPrice / priceStep) * priceStep;
    
    for (let price = firstGrid; price <= maxPrice; price += priceStep) {
      const y = height - (price - minPrice) * (height / (maxPrice - minPrice));
      
      // Format as multiplier - left side only
      const multiplierText = `${price.toFixed(price < 0.1 ? 4 : price < 1 ? 2 : 1)}x`;
      ctx.fillText(multiplierText, 25, y - 5);
    }
  }
  
  function drawInitialGrid(ctx, width, height) {
    ctx.strokeStyle = '#444';
    ctx.fillStyle = '#aaa';
    ctx.font = '400 12px DynaPuff, sans-serif';
    
    // Draw horizontal lines at 0.5, 0.75, 1.0, 1.25, 1.5
    for (let i = 0; i <= 4; i++) {
      const price = 0.5 + (i * 0.25);
      const y = height - ((price - 0.5) / 1) * height;
      
      ctx.beginPath();
      // Make all grid lines solid
      ctx.lineWidth = price === 1.0 ? 2 : 1; // Keep 1.0 line thicker
      ctx.moveTo(0, y);
      // Draw lines across full width to extend under the gradient
      ctx.lineTo(width, y);
      ctx.stroke();
      
      // Price labels - LEFT SIDE ONLY
      ctx.fillText(`${price.toFixed(2)}x`, 25, y - 5);
    }
    
    // Starting price marker
    const yPrice = height / 2; // 1.0 is in the middle
    ctx.beginPath();
    ctx.strokeStyle = 'white';
    ctx.setLineDash([5, 3]);
    ctx.moveTo(0, yPrice);
    // Draw across full width to extend under gradient
    ctx.lineTo(width, yPrice);
    ctx.stroke();
    ctx.setLineDash([]);
    
    // Starting price label - left side only
    ctx.fillStyle = 'white';
    ctx.fillText('1.0000x', 25, yPrice - 5);
  }

  // Clean up animation frame on unmount
  useEffect(() => {
    return () => {
      if (animationFrameRef.current) {
        cancelAnimationFrame(animationFrameRef.current);
      }
    };
  }, []);

  // Add Golden Hour event listeners
  useEffect(() => {
    const handleGoldenHourDrawing = (data) => {
      console.log('Received Golden Hour drawing data:', data);
      setGoldenHourDrawing(data);
      setShowGoldenHourDrawing(true);
      
      // Automatically hide after 10 seconds
      setTimeout(() => {
        setShowGoldenHourDrawing(false);
      }, 10000);
    };
    
    const handleGoldenHourUpdate = (data) => {
      setGoldenHourState(data);
    };
    
    // Register listeners
    const unsubscribeDrawing = gameService.on('goldenHourDrawing', handleGoldenHourDrawing);
    const unsubscribeUpdate = gameService.on('goldenHourUpdate', handleGoldenHourUpdate);
    
    // Get initial state
    const currentGameState = gameService.getGameState();
    if (currentGameState && currentGameState.goldenHourState) {
      setGoldenHourState(currentGameState.goldenHourState);
    }
    
    return () => {
      unsubscribeDrawing();
      unsubscribeUpdate();
    };
  }, []);
  
  // Add Golden Hour drawing close handler
  const handleCloseGoldenHourDrawing = () => {
    setShowGoldenHourDrawing(false);
  };

  // Animate golden outline during Golden Hour
  useEffect(() => {
    if (goldenHourState && goldenHourState.isActive) {
      const animateGoldenOutline = () => {
        setGoldenOutlineAnimationFrame(prev => (prev + 1) % 100);
        requestAnimationFrame(animateGoldenOutline);
      };
      
      const animationId = requestAnimationFrame(animateGoldenOutline);
      return () => cancelAnimationFrame(animationId);
    }
  }, [goldenHourState]);
  
  // Add a ref to store the animation ID
  const flakeAnimationIdRef = useRef(null);
  
  // Add dedicated animation loop for gold flakes to ensure smooth animation
  useEffect(() => {
    // Clear any existing animation to prevent multiple loops
    if (flakeAnimationIdRef.current) {
      cancelAnimationFrame(flakeAnimationIdRef.current);
      flakeAnimationIdRef.current = null;
    }
    
    if (goldenHourState && goldenHourState.isActive) {
      // Initialize flake positions with more randomness
      if (!flakesInitialized) {
        initializeFlakePositions();
        setFlakesInitialized(true);
      }
      
      let frameCount = 0;
      const animateGoldenFlakes = () => {
        // Update and render flakes directly on the dedicated canvas
        updateAndRenderFlakes();
        
        // Still keep the frame counter for other animations that might need it
        frameCount++;
        if (frameCount % 2 === 0) {
          setGoldenFlakeAnimationFrame(prev => prev + 1);
        }
        
        // Store animation ID in the ref so we can cancel it later
        flakeAnimationIdRef.current = requestAnimationFrame(animateGoldenFlakes);
      };
      
      // Start the animation
      flakeAnimationIdRef.current = requestAnimationFrame(animateGoldenFlakes);
      
      // Cleanup function
      return () => {
        if (flakeAnimationIdRef.current) {
          cancelAnimationFrame(flakeAnimationIdRef.current);
          flakeAnimationIdRef.current = null;
        }
        setFlakesInitialized(false);
      };
    } else {
      // Clean up when golden hour is not active
      setFlakesInitialized(false);
    }
  }, [goldenHourState?.isActive]); // Only re-run when the isActive state changes, not the entire object

  // Initialize an array of flake positions and properties
  const flakesRef = useRef([]);
  // Add a reference to store the initial time
  const flakeAnimationStartTimeRef = useRef(null);

  // Initialize flake positions with varied properties
  const initializeFlakePositions = () => {
    // Initialize the start time when we first set up the flakes
    flakeAnimationStartTimeRef.current = Date.now() / 1000;
    
    const container = containerRef.current;
    if (!container) return;
    
    const width = container.clientWidth;
    const height = container.clientHeight;
    
    const flakes = [];
    // Create more flakes for a richer effect
    for (let i = 0; i < 40; i++) {
      flakes.push({
        // Random positions throughout the canvas
        x: Math.random() * width,
        y: Math.random() * height,
        // Varied sizes - ensure some larger flakes (up to 4px)
        radius: Math.random() * 3 + 1,
        // Different speeds for more natural movement - use constant values
        speedX: (Math.random() - 0.5) * 0.65,
        speedY: (Math.random() - 0.5) * 0.65,
        // Add slight circular motion with fixed amplitude
        wobbleSpeed: Math.random() * 0.06,
        wobbleAmount: Math.random() * 6,
        wobbleOffset: Math.random() * Math.PI * 2,
        // Add pulsing effect with fixed values
        pulseSpeed: Math.random() * 0.06 + 0.02,
        pulseAmount: Math.random() * 0.5 + 0.5,
        // Flake brightness (some brighter, some dimmer)
        brightness: Math.random() * 0.3 + 0.7,
        // Some flakes appear closer (larger and more translucent)
        isUpClose: Math.random() < 0.15,
        // Store the initial speed to prevent increases over time
        initialSpeedX: (Math.random() - 0.5) * 0.65,
        initialSpeedY: (Math.random() - 0.5) * 0.65
      });
    }
    
    flakesRef.current = flakes;
  };

  // Update and render flakes on the dedicated canvas
  const updateAndRenderFlakes = () => {
    const canvas = goldFlakeCanvasRef.current;
    const container = containerRef.current;
    if (!canvas || !container) return;
    
    const ctx = canvas.getContext('2d');
    const width = container.clientWidth;
    const height = container.clientHeight;
    
    // Ensure canvas is sized correctly
    if (canvas.width !== width || canvas.height !== height) {
      canvas.width = width;
      canvas.height = height;
    }
    
    // Clear canvas
    ctx.clearRect(0, 0, width, height);
    
    // Draw the gold gradient background (optional, can be removed if not needed)
    const gradient = ctx.createLinearGradient(0, 0, width, height);
    gradient.addColorStop(0, 'rgba(212, 175, 55, 0.01)');
    gradient.addColorStop(0.5, 'rgba(212, 175, 55, 0.02)');
    gradient.addColorStop(1, 'rgba(212, 175, 55, 0.01)');
    ctx.fillStyle = gradient;
    ctx.fillRect(0, 0, width, height);
    
    // Update and draw each flake - USING INITIAL SPEEDS ONLY
    flakesRef.current.forEach(flake => {
      // Always use the initial speeds instead of potentially modified speeds
      flake.x += flake.initialSpeedX;
      flake.y += flake.initialSpeedY;
      
      // Wrap around edges
      if (flake.x < -15) flake.x = width + 15;
      if (flake.x > width + 15) flake.x = -15;
      if (flake.y < -15) flake.y = height + 15;
      if (flake.y > height + 15) flake.y = -15;
      
      // Fixed radius - no pulsing
      const radius = flake.radius;
      
      // Draw the flake with a fixed brightness
      ctx.fillStyle = `rgba(212, 175, 55, ${0.15 * flake.brightness})`;
      ctx.beginPath();
      ctx.arc(flake.x, flake.y, radius, 0, Math.PI * 2);
      ctx.fill();
    });
    
    // Occasionally add sparkle effects
    if (Math.random() < 0.03) { // Reduced frequency
      const sparkleX = Math.random() * width;
      const sparkleY = Math.random() * height;
      const sparkleSize = Math.random() * 3 + 2;
      
      ctx.fillStyle = 'rgba(230, 195, 100, 0.6)';
      ctx.beginPath();
      ctx.arc(sparkleX, sparkleY, sparkleSize, 0, Math.PI * 2);
      ctx.fill();
      
      ctx.fillStyle = 'rgba(230, 195, 100, 0.3)';
      ctx.beginPath();
      ctx.arc(sparkleX, sparkleY, sparkleSize * 2, 0, Math.PI * 2);
      ctx.fill();
    }
  };

  // Remove the effect that forces chart redraw for gold flakes
  // (We don't need it anymore since we're using a separate canvas)
  useEffect(() => {
    if (!goldenHourState || !goldenHourState.isActive) {
      setFlakesInitialized(false);
    }
  }, [goldenHourState]);

  // We'll keep the drawGoldenHourBackground function for the subtle gradient effect,
  // but remove the particle drawing since we're now doing that on a separate canvas

  // Function to draw golden background effect during Golden Hour
  const drawGoldenHourBackground = (ctx, width, height) => {
    if (!goldenHourState || !goldenHourState.isActive) return;
    
    // Create subtle golden gradient
    const gradient = ctx.createLinearGradient(0, 0, width, height);
    gradient.addColorStop(0, 'rgba(212, 175, 55, 0.03)');
    gradient.addColorStop(0.5, 'rgba(212, 175, 55, 0.06)');
    gradient.addColorStop(1, 'rgba(212, 175, 55, 0.03)');
    
    // Apply golden overlay
    ctx.fillStyle = gradient;
    ctx.fillRect(0, 0, width, height);
    
    // Add golden aura around the chart edges
    const edgeGradient = ctx.createRadialGradient(
      width / 2, height / 2, Math.min(width, height) * 0.3,
      width / 2, height / 2, Math.min(width, height) * 0.8
    );
    edgeGradient.addColorStop(0, 'rgba(212, 175, 55, 0)');
    edgeGradient.addColorStop(1, 'rgba(212, 175, 55, 0.05)');
    
    ctx.fillStyle = edgeGradient;
    ctx.fillRect(0, 0, width, height);
  };
  
  // Within the return statement, find where the game status is displayed
  // Typically in the top right corner or near the current token display
  
  // For the section displaying game status (look for the "COOLDOWN" or "LIVE" indicators)
  // Add the golden game indicator next to it when appropriate

  // Example game status section (modify as needed based on actual implementation)
  const renderGameStatus = () => {
    if (cooldownTimer > 0) {
      return (
        <div className="game-status" style={{
          display: 'flex',
          alignItems: 'center'
        }}>
          <Badge type="cooldown">
            COOLDOWN {formatCountdownTime(cooldownTimer)}
          </Badge>
          
          {/* Add Golden Game indicator if current game is golden */}
          {/* {goldenHourState && goldenHourState.currentGameIsGolden && (
            <GoldenGameIndicator 
              isMobile={isMobile} 
              playerEntries={goldenHourState.playerEntries || 0} 
            />
          )} */}
        </div>
      );
    } else if (gameState.active) {
      return (
        <div className="game-status" style={{
          display: 'flex',
          alignItems: 'center'
        }}>
          <Badge type="live">LIVE</Badge>
          
          {/* Add Golden Game indicator if current game is golden */}
          {/* {goldenHourState && goldenHourState.currentGameIsGolden && (
            <GoldenGameIndicator 
              isMobile={isMobile} 
              playerEntries={goldenHourState.playerEntries || 0} 
            />
          )} */}
        </div>
      );
    } else {
      return (
        <div className="game-status" style={{
          display: 'flex',
          alignItems: 'center'
        }}>
          <Badge type="inactive">NOT RUNNING</Badge>
          
          {/* Add Golden Game indicator if current game is golden */}
          {/* {goldenHourState && goldenHourState.currentGameIsGolden && (
            <GoldenGameIndicator 
              isMobile={isMobile} 
              playerEntries={goldenHourState.playerEntries || 0} 
            />
          )} */}
        </div>
      );
    }
  };
  
  // We've removed the effect that forced chart redraw on goldenFlakeAnimationFrame changes
  // since we're now using a separate canvas for the gold flakes animation
  
  // Add scroll event handler for leaderboard
  useEffect(() => {
    const leaderboardElement = leaderboardContainerRef.current;
    if (!leaderboardElement) return;

    const handleScroll = () => {
      // Check if scrolled down at all (more than 10px)
      setIsLeaderboardScrolled(leaderboardElement.scrollTop > 10);
    };

    leaderboardElement.addEventListener('scroll', handleScroll);
    
    return () => {
      leaderboardElement.removeEventListener('scroll', handleScroll);
    };
  }, []);
  
  // Add Rug Royale tournament state update
  useEffect(() => {
    if (tournamentData) {
      const isActive = tournamentData.active || tournamentData.preparing;
      
      // Calculate time remaining if tournament is active and has an end time
      let timeRemaining = null;
      let finalRound = false;
      
      if (tournamentData.active && tournamentData.endTime) {
        const now = new Date().getTime();
        const endTime = new Date(tournamentData.endTime).getTime();
        const remainingMs = endTime - now;
        
        if (remainingMs > 0) {
          // Format the remaining time
          const hours = Math.floor(remainingMs / (1000 * 60 * 60));
          const minutes = Math.floor((remainingMs % (1000 * 60 * 60)) / (1000 * 60));
          const seconds = Math.floor((remainingMs % (1000 * 60)) / 1000);
          
          if (hours > 0) {
            timeRemaining = `${hours}h ${minutes}m`;
          } else if (minutes > 0) {
            timeRemaining = `${minutes}m ${seconds}s`;
          } else {
            timeRemaining = `${seconds}s`;
            finalRound = seconds <= 0; // Final round when less than 30 seconds
          }
        } else {
          timeRemaining = "🏆 FINAL ROUND";
          finalRound = true;
        }
      }
      
      setTournamentTimeRemaining(timeRemaining);
      setIsFinalRound(finalRound);
      
      setRugRoyaleState({
        isActive,
        status: tournamentData.active ? 'LIVE' : 
                tournamentData.preparing ? 'STARTING SOON' : 
                'SCHEDULED'
      });
    } else {
      setRugRoyaleState(null);
      setTournamentTimeRemaining(null);
      setIsFinalRound(false);
    }
  }, [tournamentData]);
  
  // Update tournament countdown more frequently when less than 1 hour remains
  useEffect(() => {
    if (!tournamentData || !tournamentData.active || !tournamentData.endTime) {
      return;
    }
    
    const updateCountdown = () => {
      const now = new Date().getTime();
      const endTime = new Date(tournamentData.endTime).getTime();
      const remainingMs = endTime - now;
      
      if (remainingMs > 0) {
        // Format the remaining time
        const hours = Math.floor(remainingMs / (1000 * 60 * 60));
        const minutes = Math.floor((remainingMs % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((remainingMs % (1000 * 60)) / 1000);
        
        if (hours > 0) {
          setTournamentTimeRemaining(`${hours}h ${minutes}m`);
          setIsFinalRound(false);
        } else if (minutes > 0) {
          setTournamentTimeRemaining(`${minutes}m ${seconds}s`);
          setIsFinalRound(false);
        } else {
          setTournamentTimeRemaining(`${seconds}s`);
          setIsFinalRound(seconds < 30); // Final round when less than 30 seconds
        }
      } else {
        setTournamentTimeRemaining("Tournament ending");
        setIsFinalRound(true);
      }
    };
    
    // Update immediately
    updateCountdown();
    
    // Update every second when less than an hour remains, otherwise every minute
    const now = new Date().getTime();
    const endTime = new Date(tournamentData.endTime).getTime();
    const remainingMs = endTime - now;
    const hours = Math.floor(remainingMs / (1000 * 60 * 60));
    
    const interval = setInterval(
      updateCountdown, 
      hours > 0 ? 60000 : 1000 // Update every minute if hours > 0, otherwise every second
    );
    
    return () => clearInterval(interval);
  }, [tournamentData]);
  
  // Animate royale outline during Rug Royale tournament
  useEffect(() => {
    // Clean up any existing animation to prevent multiple loops
    if (royaleAnimationIdRef.current) {
      cancelAnimationFrame(royaleAnimationIdRef.current);
      royaleAnimationIdRef.current = null;
    }
    
    if (rugRoyaleState && rugRoyaleState.isActive) {
      const animateRoyaleOutline = () => {
        setRugRoyaleAnimationFrame(prev => (prev + 1) % 100);
        royaleAnimationIdRef.current = requestAnimationFrame(animateRoyaleOutline);
      };
      
      royaleAnimationIdRef.current = requestAnimationFrame(animateRoyaleOutline);
      
      return () => {
        if (royaleAnimationIdRef.current) {
          cancelAnimationFrame(royaleAnimationIdRef.current);
          royaleAnimationIdRef.current = null;
        }
      };
    }
  }, [rugRoyaleState?.isActive]); // Only re-run when the isActive state changes, not the entire object
  
  // Function to draw Rug Royale tournament background effect
  const drawRugRoyaleBackground = (ctx, width, height) => {
    if (!rugRoyaleState || !rugRoyaleState.isActive) return;
    
    // Create subtle green/white tournament gradient
    const gradient = ctx.createLinearGradient(0, 0, width, height);
    gradient.addColorStop(0, 'rgba(0, 193, 55, 0.03)'); // Green color
    gradient.addColorStop(0.5, 'rgba(255, 255, 255, 0.04)'); // White color
    gradient.addColorStop(1, 'rgba(0, 193, 55, 0.03)'); // Green color
    
    // Apply tournament overlay
    ctx.fillStyle = gradient;
    ctx.fillRect(0, 0, width, height);
    
    // Add green/white aura around the chart edges
    const edgeGradient = ctx.createRadialGradient(
      width / 2, height / 2, Math.min(width, height) * 0.3,
      width / 2, height / 2, Math.min(width, height) * 0.8
    );
    edgeGradient.addColorStop(0, 'rgba(255, 255, 255, 0)');
    edgeGradient.addColorStop(1, 'rgba(0, 193, 55, 0.05)');
    
    ctx.fillStyle = edgeGradient;
    ctx.fillRect(0, 0, width, height);
  };
  
  // Add state to store trades with timestamps
  const timestampedTradesRef = useRef({});
  
  // Handle completed animations - clear old second keys
  useEffect(() => {
    const clearOldTimestamps = () => {
      const now = Date.now();
      const oldestValidTimestamp = now - (10 * TRADE_MARKER_THROTTLE_MS); // Keep last 10 seconds
      
      // Filter out old timestamps
      const updatedTimestampedTrades = {};
      Object.entries(timestampedTradesRef.current).forEach(([key, trade]) => {
        const timeKey = parseInt(key) * TRADE_MARKER_THROTTLE_MS;
        if (timeKey > oldestValidTimestamp) {
          updatedTimestampedTrades[key] = trade;
        }
      });
      
      timestampedTradesRef.current = updatedTimestampedTrades;
    };
    
    // Run cleanup every 5 seconds
    const cleanupInterval = setInterval(clearOldTimestamps, 5000);
    
    return () => clearInterval(cleanupInterval);
  }, []);
  
  // Add state to track previous position state for smooth transitions
  const [visualPositionQty, setVisualPositionQty] = useState(positionQty);
  const [isExitingPosition, setIsExitingPosition] = useState(false);
  const [profitState, setProfitState] = useState(cumulativePnL >= 0 ? 'profit' : 'loss');
  
  // Track position changes for animations
  useEffect(() => {
    // If we have position, update the visual state immediately
    if (positionQty > 0) {
      setVisualPositionQty(positionQty);
      setIsExitingPosition(false);
      // Always use profit state for preround, regardless of previous PnL
      if (allowPreRoundBuys) {
        setProfitState('profit');
      } else {
        setProfitState(cumulativePnL >= 0 ? 'profit' : 'loss');
      }
    } 
    // If position is being closed, trigger exit animation
    else if (visualPositionQty > 0 && positionQty === 0) {
      setIsExitingPosition(true);
      // After animation completes, update the visual state
      const timer = setTimeout(() => {
        setVisualPositionQty(0);
        setIsExitingPosition(false);
      }, 600); // Increased to 600ms to ensure DOM updates after animations complete
      return () => clearTimeout(timer);
    }
  }, [positionQty, cumulativePnL, visualPositionQty, allowPreRoundBuys]);
  
  // Separate effect for profit/loss state transitions during active trading only
  useEffect(() => {
    if (positionQty > 0 && !allowPreRoundBuys) {
      // Smoothly transition between profit/loss states, but only during active trading
      setProfitState(cumulativePnL >= 0 ? 'profit' : 'loss');
    } else if (positionQty > 0 && allowPreRoundBuys) {
      // Always force profit state during preround
      setProfitState('profit');
    }
  }, [cumulativePnL, positionQty, allowPreRoundBuys]);
  
  // First, let's create a helper function to determine the correct color based on state
  // Add this function before the return statement
  const getProfitColor = () => {
    // In preround, ALWAYS return green/profit colors
    if (allowPreRoundBuys) {
      return {
        solid: '#00C137', // Green
        transparent: 'rgba(0, 193, 55, 0.7)',
        glow: 'rgba(0, 193, 55, 0.8)',
        gradient: 'linear-gradient(90deg, #FFFFFF, #00ff44)'
      };
    }
    
    // Otherwise use colors based on profit state
    if (profitState === 'profit' || cumulativePnL >= 0) {
      return {
        solid: '#00C137', // Green
        transparent: 'rgba(0, 193, 55, 0.7)',
        glow: 'rgba(0, 193, 55, 0.8)',
        gradient: 'linear-gradient(90deg, #FFFFFF, #00ff44)'
      };
    } else {
      return {
        solid: '#E7000B', // Red
        transparent: 'rgba(220, 53, 69, 0.7)',
        glow: 'rgba(220, 53, 69, 0.8)',
        gradient: 'linear-gradient(90deg, #FFFFFF, #ff3333)'
      };
    }
  };
  
  // Add state for rugpool overlay
  const [showRugpoolOverlay, setShowRugpoolOverlay] = useState(false);
  const [rugpoolOverlayType, setRugpoolOverlayType] = useState(null);
  const [rugpoolOverlayData, setRugpoolOverlayData] = useState(null);
  const rugpoolStateCacheRef = useRef({
    playerEntries: [],
    rugpoolAmount: 0,
    threshold: 3,
    instarugCount: 0
  });

  // Add effect to monitor rugpool events and display overlay
  useEffect(() => {
    // Cache rugpool state from gameState
    if (gameState && gameState.rugpool) {
      rugpoolStateCacheRef.current = {
        playerEntries: gameState.rugpool.playerEntries || [],
        rugpoolAmount: gameState.rugpool.rugpoolAmount || 0,
        threshold: gameState.rugpool.threshold || 3,
        instarugCount: gameState.rugpool.instarugCount || 0,
        totalEntries: gameState.rugpool.totalEntries || 0,
        playersWithEntries: gameState.rugpool.playersWithEntries || 0,
        solPerEntry: gameState.rugpool.solPerEntry || 0
      };
    }

    // Log current rugpool overlay state for debugging
    // console.log("[RUGCHART] Current rugpool overlay state:", { 
    //   showOverlay: showRugpoolOverlay,
    //   type: rugpoolOverlayType,
    //   hasData: !!rugpoolOverlayData,
    //   playerEntries: rugpoolOverlayData?.playerEntries?.length || 0
    // });

    // Handle rugpool events
    const handleRugpoolEvent = (eventData) => {
      if (!eventData) return;
      
      console.log("[RUGCHART] Received rugpool event:", eventData);
      
      // Request latest rugpool status to ensure we have the most recent data
      gameService.getRugpoolStatus();
      
      // Get playerEntries from event data or cached state, but NEVER create placeholders
      let playerEntries = eventData.playerEntries || [];
      if (playerEntries.length === 0 && rugpoolStateCacheRef?.current?.playerEntries) {
        playerEntries = rugpoolStateCacheRef.current.playerEntries;
      }
      
      // Create enhanced data object with actual data only - no placeholders
      const enhancedEventData = {
        ...eventData,
        playerEntries: playerEntries,
        rugpoolAmount: eventData.rugpoolAmount || eventData.totalPool || rugpoolStateCacheRef.current.rugpoolAmount || 0.000,
        threshold: eventData.threshold || rugpoolStateCacheRef.current.threshold || 3,
        totalEntries: eventData.totalEntries || rugpoolStateCacheRef.current.totalEntries || 
                    (playerEntries.length > 0 ? playerEntries.reduce((sum, p) => sum + (p.entries || 0), 0) : 0),
        playersWithEntries: eventData.playersWithEntries || 
                          rugpoolStateCacheRef.current.playersWithEntries || 
                          playerEntries.length
      };
      
      console.log("[RUGCHART] Enhanced rugpool event data:", enhancedEventData);
      
      // If we're already showing an overlay, we need to handle this properly
      if (showRugpoolOverlay) {
        // Clear any existing timeout
        if (forceShowTimeoutRef.current) {
          clearTimeout(forceShowTimeoutRef.current);
        }
        
        // Close the current overlay then show the new one after a short delay
        setShowRugpoolOverlay(false);
        
        forceShowTimeoutRef.current = setTimeout(() => {
          // Now show the new overlay
          setRugpoolOverlayType(eventData.type);
          setRugpoolOverlayData(enhancedEventData);
          setShowRugpoolOverlay(true);
          forceShowTimeoutRef.current = null;
        }, 300); // Short delay to allow previous overlay to close
        
        return;
      }
      
      if (eventData.type === 'candle') {
        // Show candle overlay
        setRugpoolOverlayType('candle');
        setRugpoolOverlayData(enhancedEventData);
        setShowRugpoolOverlay(true);
      } else if (eventData.type === 'drawing') {
        // Always show drawing overlay regardless of player entries
        setRugpoolOverlayType('drawing');
        setRugpoolOverlayData(enhancedEventData);
        setShowRugpoolOverlay(true);
      }
    };
    
    // Direct handler for rugpoolDrawing events from socket
    const handleDirectRugpoolDrawing = (data) => {
      console.log("[RUGCHART] DIRECT rugpoolDrawing event received:", data);
      
      // Always request updated rugpool data to try to get fresh player entries
      gameService.getRugpoolStatus();
      
      // Use player entries from event data or cached state, never create placeholders
      let playerEntries = null;
      
      if (data.playerEntries && data.playerEntries.length > 0) {
        // Make a deep copy to ensure we don't lose the reference later
        playerEntries = JSON.parse(JSON.stringify(data.playerEntries));
        console.log("[RUGCHART] Using playerEntries from event data:", playerEntries.length);
      } else if (rugpoolStateCacheRef.current && rugpoolStateCacheRef.current.playerEntries && rugpoolStateCacheRef.current.playerEntries.length > 0) {
        // Make a deep copy to ensure we don't lose the reference later
        playerEntries = JSON.parse(JSON.stringify(rugpoolStateCacheRef.current.playerEntries));
        console.log("[RUGCHART] Using playerEntries from cache:", playerEntries.length);
      } else {
        // Last resort - try to get from current game state
        const currentGameState = gameService.getGameState();
        if (currentGameState?.rugpool?.playerEntries && currentGameState.rugpool.playerEntries.length > 0) {
          // Make a deep copy to ensure we don't lose the reference later
          playerEntries = JSON.parse(JSON.stringify(currentGameState.rugpool.playerEntries));
          console.log("[RUGCHART] Using playerEntries from gameState:", playerEntries.length);
        } else {
          console.warn("[RUGCHART] No player entries found anywhere!");
          playerEntries = []; // Initialize as empty array
        }
      }
      
      // Create enhanced data with player entries - real data only
      const enhancedData = {
        ...data,
        type: 'drawing', // Set type to drawing for overlay
        playerEntries: playerEntries, // Ensure this is the deep copy we just created
        // Use actual pool amount from event or reasonable default
        rugpoolAmount: data.totalPool || data.rugpoolAmount || rugpoolStateCacheRef.current.rugpoolAmount || 0,
        threshold: data.threshold || rugpoolStateCacheRef.current.threshold || 3
      };
      
      console.log("[RUGCHART] Showing drawing overlay with data:", enhancedData);
      console.log("[RUGCHART] Player entries count:", enhancedData.playerEntries?.length || 0);
      
      // Always show the overlay regardless of player entries
      setRugpoolOverlayType('drawing');
      setRugpoolOverlayData(enhancedData);
      setShowRugpoolOverlay(true);
    };
    
    // Start monitoring rugpool events - both approaches
    const unsubscribeMonitor = monitorRugpoolDrawings(handleRugpoolEvent);
    const unsubscribeDirectEvent = gameService.on('rugpoolDrawing', handleDirectRugpoolDrawing);
    
    // Clean up on unmount
    return () => {
      unsubscribeMonitor();
      unsubscribeDirectEvent(); 
      if (forceShowTimeoutRef.current) {
        clearTimeout(forceShowTimeoutRef.current);
      }
    };
  }, [showRugpoolOverlay, gameState]);

  // Add ref for timeout to handle forced display of overlay
  const forceShowTimeoutRef = useRef(null);

  return (
    <div 
      ref={containerRef}
      style={{ 
        position: 'relative',
        width: '100%',
        height: '100%',
        backgroundColor: colors.primary.black, // Always maintain the base background color
        backgroundImage: visualPositionQty > 0 ? 
          `radial-gradient(circle at center, ${colors.primary.black} 30%, ${allowPreRoundBuys || profitState === 'profit' ? 'rgba(0, 193, 55, 0.15)' : 'rgba(220, 53, 69, 0.15)'} 100%)` : 
          'none',
        backgroundBlendMode: 'soft-light',
        borderRadius: '8px',
        // Instead of changing outline/border, use a consistent border with different colors
        border: goldenHourState && goldenHourState.isActive ? 
          `4px solid rgba(212, 175, 55, ${0.5 + Math.sin(goldenOutlineAnimationFrame / 15) * 0.3})` : 
          rugRoyaleState && rugRoyaleState.isActive ?
          `4px solid rgba(${Math.sin(rugRoyaleAnimationFrame / 15) > 0 ? '255, 255, 255' : '0, 193, 55'}, ${0.5 + Math.abs(Math.sin(rugRoyaleAnimationFrame / 15)) * 0.3})` :
          '4px solid #18181B', // Always have a 4px border to maintain consistent size
        // Remove outlineOffset to prevent layout shifts
        overflow: 'hidden',
        boxShadow: goldenHourState && goldenHourState.isActive ? 
          `0 0 20px rgba(212, 175, 55, ${0.3 + Math.sin(goldenOutlineAnimationFrame / 20) * 0.15}), 
          inset 0 0 15px rgba(212, 175, 55, ${0.1 + Math.sin(goldenOutlineAnimationFrame / 25) * 0.05})` :
          rugRoyaleState && rugRoyaleState.isActive ?
          `0 0 20px rgba(0, 193, 55, ${0.3 + Math.sin(rugRoyaleAnimationFrame / 20) * 0.15}), 
          inset 0 0 15px rgba(255, 255, 255, ${0.1 + Math.sin(rugRoyaleAnimationFrame / 25) * 0.05})` :
          (visualPositionQty > 0) ? '0 0 15px rgba(255, 255, 255, 0.2)' : 'none',
        transition: 'background-image 0.6s ease, box-shadow 0.6s ease, border-color 0.6s ease',
        animation: goldenHourState && goldenHourState.isActive ? 'goldenPulse 5s infinite ease-in-out' : 
                  rugRoyaleState && rugRoyaleState.isActive ? 'royalePulse 5s infinite ease-in-out' : 
                  (visualPositionQty > 0) ? 'activeBorderPulse 3s infinite ease-in-out' : 'none',
      }}
      className={(visualPositionQty > 0) ? "active-chart" : ""}
    >
      {/* Always render a solid black background regardless of animation state */}
      <div 
        style={{
          position: 'absolute',
          top: 0,
          left: 0,
          width: '100%',
          height: '100%',
          backgroundColor: colors.primary.black,
          zIndex: 0
        }}
      />

      {/* Add Rugpool mini button in top left corner */}
      <div style={{
        position: 'absolute',
        top: '10px',
        left: '15px',
        zIndex: 25, // Increased z-index to ensure dropdown works
        transform: isMobile ? 'scale(0.7)' : 'scale(0.9)', // Smaller size on mobile
        transformOrigin: 'left top',
        pointerEvents: allowPreRoundBuys ? 'none' : 'auto', // Hide during pre-round buys mode
        opacity: allowPreRoundBuys ? 0 : 1, // Hide during pre-round buys mode
        transition: 'opacity 0.3s ease'
      }}>
        <Rugpool expanded={false} isMobile={isMobile} miniButton={true} maxHeight={isMobile ? 200 : 300} scrollable={true} />
      </div>

      {/* Additional PnL background effect for stronger visual impact */}
      <div 
        className="position-background-effect"
        style={{
          position: 'absolute',
          top: 0,
          left: 0,
          width: '100%',
          height: '100%',
          background: `radial-gradient(circle at center, transparent 10%, ${allowPreRoundBuys || profitState === 'profit' ? 'rgba(0, 193, 55, 0.1)' : 'rgba(220, 53, 69, 0.1)'} 70%, ${allowPreRoundBuys || profitState === 'profit' ? 'rgba(0, 193, 55, 0.2)' : 'rgba(220, 53, 69, 0.2)'} 100%)`,
          opacity: visualPositionQty > 0 ? 1 : 0, // Base opacity only on visualPositionQty for smooth transitions
          zIndex: 1,
          pointerEvents: 'none',
          mixBlendMode: 'screen',
          transition: 'opacity 0.6s ease, background 0.6s ease' // Increase transition time slightly
        }}
      />

      <canvas
        ref={canvasRef}
        style={{ 
          width: '100%',
          height: '100%',
          display: 'block',
          imageRendering: 'crisp-edges',
          position: 'relative',
          zIndex: 2
        }}
      />

      {/* Trading animation overlay - position absolute with same dimensions as parent */}
      <div 
        className="active-border-animation"
        style={{
          position: 'absolute',
          top: 0,
          left: 0,
          width: '100%',
          height: '100%',
          pointerEvents: 'none',
          zIndex: 10,
          opacity: visualPositionQty > 0 ? 1 : 0,
          transition: 'opacity 0.6s ease',
          // No transform or sizing changes to the container
          borderRadius: '8px', // Match container border radius
        }}
      />

      {/* Add CSS keyframes for animations - UPDATE ALL COLORS HERE */}
      <style jsx="true">{`
        @keyframes fadeInGradient {
          0% { opacity: 0; }
          100% { opacity: 1; }
        }
        
        @keyframes goldenPulse {
          0% { 
            box-shadow: 0 0 20px rgba(212, 175, 55, 0.3), inset 0 0 15px rgba(212, 175, 55, 0.1);
          }
          50% { 
            box-shadow: 0 0 30px rgba(212, 175, 55, 0.4), inset 0 0 20px rgba(212, 175, 55, 0.15);
          }
          100% { 
            box-shadow: 0 0 20px rgba(212, 175, 55, 0.3), inset 0 0 15px rgba(212, 175, 55, 0.1);
          }
        }
        
        @keyframes activeBorderPulse {
          0% { 
            box-shadow: 0 0 10px rgba(255, 255, 255, 0.2);
          }
          50% { 
            box-shadow: 0 0 20px rgba(255, 255, 255, 0.3);
          }
          100% { 
            box-shadow: 0 0 10px rgba(255, 255, 255, 0.2);
          }
        }
        
        .active-chart {
          position: relative;
          transition: opacity 0.6s ease;
        }
        
        .active-border-animation {
          position: absolute;
          top: 0;
          left: 0;
          right: 0;
          bottom: 0;
          overflow: visible;
          transition: opacity 0.6s ease;
        }
        
        .active-border-animation::before {
          content: '';
          position: absolute;
          /* Position inset by 4px to account for the parent's border */
          top: 4px;
          left: 4px;
          right: 4px;
          bottom: 4px;
          border-radius: 4px;
          border: 2px solid transparent;
          background: ${allowPreRoundBuys ? 'linear-gradient(90deg, #FFFFFF, #00ff44)' : 
                      (profitState === 'profit' ? 
                        'linear-gradient(90deg, #FFFFFF, #00ff44)' : 
                        'linear-gradient(90deg, #FFFFFF, #ff3333)')};
          
          /* Use mask-based approach instead of clip-path to avoid zoom effect */
          -webkit-mask: 
            linear-gradient(#fff 0 0) padding-box, 
            linear-gradient(#fff 0 0);
          -webkit-mask-composite: xor;
          mask-composite: exclude;
          
          /* Animate opacity instead of clip-path */
          animation: glowBorder 2s ease-in-out forwards;
          transition: background 0.5s ease;
        }
        
        @keyframes glowBorder {
          0% {
            opacity: 0;
            box-shadow: 0 0 0px rgba(255, 255, 255, 0);
          }
          
          100% {
            opacity: 1;
            box-shadow: 0 0 18px ${allowPreRoundBuys ? 
                               'rgba(0, 193, 55, 0.7)' : 
                               (profitState === 'profit' ? 
                                'rgba(0, 193, 55, 0.7)' : 
                                'rgba(220, 53, 69, 0.7)')};
          }
        }
        
        /* Create pulsing effect after the border is drawn */
        .active-border-animation::after {
          content: '';
          position: absolute;
          /* Position inset by 4px to account for the parent's border */
          top: 4px;
          left: 4px;
          right: 4px;
          bottom: 4px;
          border-radius: 4px;
          border: 2px solid ${allowPreRoundBuys ? 
                          'rgba(0, 193, 55, 0.7)' : 
                          (profitState === 'profit' ? 
                            'rgba(0, 193, 55, 0.7)' : 
                            'rgba(220, 53, 69, 0.7)')};
          opacity: 0;
          box-shadow: 0 0 15px ${allowPreRoundBuys ? 
                            'rgba(0, 193, 55, 0.5)' : 
                            (profitState === 'profit' ? 
                              'rgba(0, 193, 55, 0.5)' : 
                              'rgba(220, 53, 69, 0.5)')};
          animation: pulseBorder 2s 2s infinite alternate ease-in-out;
          transition: border 0.5s ease, box-shadow 0.5s ease;
        }
        
        @keyframes pulseBorder {
          0% {
            opacity: 0.5;
            box-shadow: 0 0 10px ${allowPreRoundBuys ? 
                              'rgba(0, 193, 55, 0.5)' : 
                              (profitState === 'profit' ? 
                                'rgba(0, 193, 55, 0.5)' : 
                                'rgba(220, 53, 69, 0.5)')};
          }
          100% {
            opacity: 1;
            box-shadow: 0 0 25px ${allowPreRoundBuys ? 
                              'rgba(0, 193, 55, 0.8)' : 
                              (profitState === 'profit' ? 
                                'rgba(0, 193, 55, 0.8)' : 
                                'rgba(220, 53, 69, 0.8)')};
          }
        }
      `}</style>
      
      {/* Add CSS keyframes for animations - Update the second set of animations */}
      <style jsx="true">{`
        @keyframes goldenPulse {
          0% { 
            box-shadow: 0 0 20px rgba(212, 175, 55, 0.3), inset 0 0 15px rgba(212, 175, 55, 0.1);
          }
          50% { 
            box-shadow: 0 0 30px rgba(212, 175, 55, 0.4), inset 0 0 20px rgba(212, 175, 55, 0.15);
          }
          100% { 
            box-shadow: 0 0 20px rgba(212, 175, 55, 0.3), inset 0 0 15px rgba(212, 175, 55, 0.1);
          }
        }
        
        @keyframes activeBorderPulse {
          0% { 
            box-shadow: 0 0 10px rgba(255, 255, 255, 0.2);
          }
          50% { 
            box-shadow: 0 0 20px rgba(255, 255, 255, 0.3);
          }
          100% { 
            box-shadow: 0 0 10px rgba(255, 255, 255, 0.2);
          }
        }
        
        .active-chart {
          position: relative;
        }
        
        /* Remove the existing .active-border-animation styles from this second style block
           as they are already defined in the first style block */
        
        /* Remove existing drawBorder and pulseBorder animations that cause size changes */
      `}</style>
      
      {/* Tournament Countdown Timer at top center */}
      {rugRoyaleState && rugRoyaleState.isActive && tournamentTimeRemaining && (
        <div
          style={{
            position: 'absolute',
            top: 0,
            left: '50%',
            transform: 'translateX(-50%)',
            backgroundColor: isFinalRound ? 'rgba(220, 53, 69, 0.8)' : 'rgba(0, 193, 55, 0.8)',
            color: '#FFFFFF',
            padding: '4px 12px',
            borderRadius: '0 0 8px 8px',
            fontSize: isMobile ? '11px' : '13px',
            fontWeight: 'bold',
            zIndex: 10,
            boxShadow: '0 2px 6px rgba(0, 0, 0, 0.3)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            backdropFilter: 'blur(1px)',
            textShadow: '0 1px 1px rgba(0, 0, 0, 0.5)',
            transition: 'background-color 0.3s ease, opacity 0.3s ease',
            opacity: isPriceInUpperArea ? 0.2 : 0.8 // Fade to 20% opacity when price is in upper area
          }}
        >
          {isFinalRound ? "🏆 FINAL ROUND" : `🏆 ${tournamentTimeRemaining}`}
        </div>
      )}
      
      {/* ADDITIONAL CSS STYLES FURTHER DOWN - UPDATE THOSE TOO */}
      
      {/* Add separate canvas for gold flakes */}
      {goldenHourState && goldenHourState.isActive && (
        <canvas
          ref={goldFlakeCanvasRef}
          style={{
            position: 'absolute',
            top: 0,
            left: 0,
            width: '100%',
            height: '100%',
            pointerEvents: 'none',
            zIndex: 3
          }}
        />
      )}
      
      {/* Add subtle golden filter during Golden Hour */}
      {goldenHourState && goldenHourState.isActive && (
        <div 
          style={{
            position: 'absolute',
            top: 0,
            left: 0,
            width: '100%',
            height: '100%',
            background: 'radial-gradient(circle at center, rgba(212, 175, 55, 0) 50%, rgba(212, 175, 55, 0.05) 100%)',
            mixBlendMode: 'overlay',
            pointerEvents: 'none',
            zIndex: 2
          }}
        />
      )}
      
      {/* Add subtle blue filter during Rug Royale tournament */}
      {rugRoyaleState && rugRoyaleState.isActive && (
        <div 
          style={{
            position: 'absolute',
            top: 0,
            left: 0,
            width: '100%',
            height: '100%',
            background: 'radial-gradient(circle at center, rgba(255, 255, 255, 0) 50%, rgba(0, 193, 55, 0.05) 100%)',
            mixBlendMode: 'overlay',
            pointerEvents: 'none',
            zIndex: 2
          }}
        />
      )}
      
      {/* Semi-transparent overlay for preround */}
      <div 
        style={{
          position: 'absolute',
          top: 0,
          left: 0,
          width: '100%',
          height: '100%',
          backgroundColor: 'rgba(0, 0, 0, ' + overlayOpacity + ')',
          zIndex: 2,
          pointerEvents: 'none',
          transition: !allowPreRoundBuys ? 'none' : 'background-color 0.5s ease-out',
        }}
      />
      
      {/* Add CSS keyframes for animations */}
      <style jsx="true">{`
        @keyframes goldenPulse {
          0% { 
            box-shadow: 0 0 20px rgba(212, 175, 55, 0.3), inset 0 0 15px rgba(212, 175, 55, 0.1);
          }
          50% { 
            box-shadow: 0 0 30px rgba(212, 175, 55, 0.4), inset 0 0 20px rgba(212, 175, 55, 0.15);
          }
          100% { 
            box-shadow: 0 0 20px rgba(212, 175, 55, 0.3), inset 0 0 15px rgba(212, 175, 55, 0.1);
          }
        }
        
        @keyframes activeBorderPulse {
          0% { 
            box-shadow: 0 0 10px rgba(255, 255, 255, 0.2);
          }
          50% { 
            box-shadow: 0 0 20px rgba(255, 255, 255, 0.3);
          }
          100% { 
            box-shadow: 0 0 10px rgba(255, 255, 255, 0.2);
          }
        }
        
        .active-chart {
          position: relative;
        }
        
        .active-border-animation {
          position: absolute;
          top: -4px;
          left: -4px;
          right: -4px;
          bottom: -4px;
          border-radius: 8px;
          box-shadow: 0 0 15px ${allowPreRoundBuys ? 
                            'rgba(0, 255, 0, 0.5)' : 
                            (cumulativePnL >= 0 ? 
                              'rgba(0, 255, 0, 0.5)' : 
                              'rgba(255, 0, 0, 0.5)')};
          overflow: hidden;
        }
        
        .active-border-animation:before {
          content: '';
          position: absolute;
          top: 0;
          left: 0;
          width: 100%;
          height: 100%;
          border: 4px solid transparent;
          border-radius: 8px;
          background: linear-gradient(90deg, #FFFFFF, ${allowPreRoundBuys ? 
                                                    '#00FF00' : 
                                                    (cumulativePnL >= 0 ? 
                                                      '#00FF00' : 
                                                      '#FF0000')}) border-box;
          -webkit-mask: 
            linear-gradient(#fff 0 0) padding-box, 
            linear-gradient(#fff 0 0);
          -webkit-mask-composite: xor;
          mask-composite: exclude;
          animation: drawBorder 1.5s cubic-bezier(0.76, 0, 0.24, 1) forwards, pulseBorder 2s 1.5s infinite alternate ease-in-out;
          box-shadow: 0 0 15px rgba(255, 255, 255, 0.7);
          opacity: 0;
        }
        
        @keyframes drawBorder {
          0% {
            opacity: 1;
            clip-path: polygon(0 0, 0 0, 0 0, 0 0);
          }
          25% {
            opacity: 1;
            clip-path: polygon(0 0, 100% 0, 100% 0, 0 0);
          }
          50% {
            opacity: 1;
            clip-path: polygon(0 0, 100% 0, 100% 100%, 0 0);
          }
          75% {
            opacity: 1;
            clip-path: polygon(0 0, 100% 0, 100% 100%, 0 100%);
          }
          100% {
            opacity: 1;
            clip-path: polygon(0 0, 100% 0, 100% 100%, 0 100%);
          }
        }
        
        @keyframes pulseBorder {
          0% {
            box-shadow: 0 0 10px ${allowPreRoundBuys ? 
                              'rgba(0, 255, 0, 0.5)' : 
                              (cumulativePnL >= 0 ? 
                                'rgba(0, 255, 0, 0.5)' : 
                                'rgba(255, 0, 0, 0.5)')};
          }
          100% {
            box-shadow: 0 0 25px ${allowPreRoundBuys ? 
                              'rgba(0, 255, 0, 0.8)' : 
                              (cumulativePnL >= 0 ? 
                                'rgba(0, 255, 0, 0.8)' : 
                                'rgba(255, 0, 0, 0.8)')};
          }
        }
      `}</style>
      
      {/* Animation toggle button at bottom left */}
      {/* <div 
        style={{
          position: 'absolute',
          bottom: '10px',
          left: '10px',
          zIndex: 3,
          display: 'flex',
          alignItems: 'center',
          background: 'rgba(0, 0, 0, 0.6)',
          borderRadius: '4px',
          padding: '4px 8px',
          cursor: 'pointer',
          fontSize: '12px',
          color: animationsEnabled ? '#4aff91' : '#aaa',
          transition: 'all 0.2s ease'
        }}
        onClick={() => setAnimationsEnabled(prev => !prev)}
      >
        <span 
          style={{ 
            marginRight: '6px',
            fontSize: '16px' 
          }}
        >
          {animationsEnabled ? '🎬' : '⏸️'}
        </span>
        <span>Animations: {animationsEnabled ? 'ON' : 'OFF'}</span>
      </div> */}
      
      {/* Pre-round overlay - top text only */}
      {allowPreRoundBuys && !gameState.cooldownPaused && (
        <div style={{
          position: 'absolute',
          top: 0,
          left: 0,
          width: '75%',
          zIndex: 3,
          pointerEvents: 'none',
          padding: isMobile ? '14px' : '20px'
        }}>
          {/* Title */}
          <div style={{
            color: '#FFFFFF',
            fontSize: isMobile ? '14px' : '24px',
            fontWeight: 'bold',
            marginBottom: '5px',
            fontFamily: 'DynaPuff, sans-serif',
            textTransform: 'uppercase',
            letterSpacing: '1px'
          }}>
            PRESALE
          </div>
          
          {/* Subtitle */}
          <div style={{
            color: '#888888',
            fontSize: isMobile ? '10px' : '14px',
            marginBottom: '0px',
            display: 'flex',
            flexDirection: 'column',
            alignItems: 'flex-start',
            fontFamily: 'DynaPuff, sans-serif',
            lineHeight: '1.5'
          }}>
            <span>Buy a guaranteed position at <span style={{ color: '#2BFF64', fontWeight: 'bold' }}>1.00x</span></span>
            <span>before the round starts</span>
          </div>
        </div>
      )}
      
      {/* Countdown timer - separate from top text to span full width */}
      {allowPreRoundBuys && !gameState.cooldownPaused && (
        <div style={{
          position: 'absolute',
          top: '50%',
          left: '50%', // Center from the left edge of entire container
          transform: 'translate(-50%, -50%)', // Center both horizontally and vertically
          width: '90%', // Make slightly less than 100% to account for padding
          display: 'flex',
          flexDirection: 'column',
          alignItems: 'center',
          justifyContent: 'center',
          zIndex: 3, // Higher than other elements
          pointerEvents: 'none'
        }}>
          {/* Next round text */}
          <div style={{
            color: '#FFFFFF',
            fontSize: '16px',
            marginBottom: '5px',
            fontFamily: 'DynaPuff, sans-serif',
            opacity: 0.9,
            fontWeight: '400'
          }}>
            Next round in...
          </div>
          
          {/* Countdown timer using RoyaleText */}
          <div>
            <RoyaleText 
              text={formatTime(cooldownTimer)}
              color="#FFC700"
              size="large"
              style={{ 
                fontSize: '52px',
                fontFamily: 'DynaPuff, sans-serif',
                fontWeight: 'bold',
                lineHeight: '1.2'
              }}
            />
          </div>
        </div>
      )}
      
      {/* Cooldown paused overlay */}
      {allowPreRoundBuys && gameState.cooldownPaused && (
        <div style={{
          position: 'absolute',
          top: 0,
          left: 0,
          width: '75%',
          height: '100%',
          display: 'flex',
          justifyContent: 'center',
          alignItems: 'center',
          zIndex: 5,
          pointerEvents: 'none'
        }}>
          <div style={{
            color: 'rgba(255, 50, 50, 0.7)',
            fontSize: '28px',
            fontWeight: 'bold',
            textAlign: 'center'
          }}>
            {gameState.pauseMessage || "Server restarting... (ETA < 2 minutes)"}
          </div>
        </div>
      )}
      
      {/* Gradient overlay for leaderboard area */}
      <div style={{
        position: 'absolute',
        top: '4px',
        right: '4px',
        width: `calc(${!isMobile ? '30%' : '40%'} - 4px)`, // Match the leaderboard width based on mobile status
        height: 'calc(100% - 8px)',
        background: `linear-gradient(to right, ${colors.primary.black}00 0%, ${colors.primary.black}66 20%, ${colors.primary.black}B3 40%, ${colors.primary.black}E6 70%, ${colors.primary.black} 90%)`,
        zIndex: 3,
        pointerEvents: 'none', // Ensure clicks pass through the overlay
      }}></div>
      
      {/* Share/PNL Button - Updated to use leaderboard data for current player */}
      {(positionQty > 0 || cumulativePnL !== 0 || (trades && trades.filter(trade => trade.playerId === playerId).length > 0)) && (
        <SharePnlButton
          rugged={rugged}
          cumulativePnL={(() => {
            // First try to get PNL from leaderboard
            const playerInLeaderboard = leaderboard.find(entry => entry.id === playerId);
            if (playerInLeaderboard) {
              return playerInLeaderboard.pnl;
            }
            // Fall back to prop if not in leaderboard
            return cumulativePnL;
          })()}
          pnlPercent={(() => {
            // First try to get pnlPercent from leaderboard
            const playerInLeaderboard = leaderboard.find(entry => entry.id === playerId);
            if (playerInLeaderboard) {
              return playerInLeaderboard.pnlPercent;
            }
            // Fall back to prop if not in leaderboard
            return pnlPercent;
          })()}
          selectedCoin={selectedCoin}
          isMobile={isMobile}
          onShareClick={handleOpenShareModal}
          positionQty={positionQty}
          tokenTicker={selectedCoin ? (selectedCoin.address === "0xPractice" ? "FREE" : selectedCoin.ticker) : "SOL"}
          allowPreRoundBuys={allowPreRoundBuys}
        />
      )}

      {/* Position tracker - overlays chart */}
      {/* <div style={{ 
        position: 'absolute', 
        top: '10px', 
        left: '10px',
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'flex-start',
        zIndex: 3
      }}>
        <div style={{ 
          padding: '5px 10px', 
          backgroundColor: 'rgba(0,0,0,0.6)', 
          color: 'white',
          borderRadius: '4px',
          marginBottom: '5px',
          fontSize: '12px'
        }}>
          {positionQty > 0 ? (
            <>Size: {positionQty.toFixed(4)}</>
          ) : (
            <>No Position</>
          )}
        </div>
      </div> */}

      {/* Share modal that persists even when game state changes */}
      {showShareModal && shareData.hasData && (
        <ShareModal
          isOpen={true}
          onClose={handleCloseShareModal}
          username={shareData.username}
          cumulativePnL={shareData.cumulativePnL}
          pnlPercent={shareData.pnlPercent}
          selectedCoin={shareData.selectedCoin}
          candles={shareData.candles}
          trades={shareData.trades}
          positionQty={shareData.positionQty}
          tokenImages={tokenImageCache}
          playerId={playerId}
          onDownload={() => {
            try {
              console.log('DOWNLOADING SHARE IMAGE WITH CANDLES:', shareData.candles.length);
              const canvas = document.querySelector('canvas[data-share-canvas="true"]');
              if (!canvas) {
                console.error('Share canvas not found for download');
                return;
              }
              
              // Get the image data
              const dataUrl = canvas.toDataURL("image/png");
              
              // Create download link
              const link = document.createElement('a');
              link.download = `rugs-fun-result-${new Date().getTime()}.png`;
              link.href = dataUrl;
              link.click();
            } catch (err) {
              console.error("Error downloading image:", err);
            }
          }}
          onShareOnX={() => {
            // Get token ticker/symbol
            const tokenText = shareData.selectedCoin ? (shareData.selectedCoin.symbol || shareData.selectedCoin.ticker || 'SOL') : 'SOL';
            
            // Format the share text
            const text = `just ${shareData.cumulativePnL >= 0 ? 'made' : 'lost'} ${Math.abs(shareData.cumulativePnL).toFixed(3)} $${tokenText} on @rugsdotfun! LFG`;
            const url = 'https://rugs.fun';
            
            // URL encode the text and URL
            const encodedText = encodeURIComponent(text);
            const encodedUrl = encodeURIComponent(url);
            
            // Detect if user is on a mobile device
            const isMobileDevice = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
            
            if (isMobileDevice) {
              // Use X (Twitter) deep link for mobile
              const twitterDeepLink = `twitter://post?message=${encodedText}`;
              
              // Try opening the deep link
              window.location.href = twitterDeepLink;
              
              // Set a timeout to fallback to web URL if deep link fails
              setTimeout(() => {
                // If we're still here after a delay, the deep link failed
                // Use the web intent URL as a fallback
                const webFallbackUrl = `https://twitter.com/intent/tweet?text=${encodedText}&url=${encodedUrl}`;
                window.location.href = webFallbackUrl;
              }, 500);
            } else {
              // For desktop, use the standard web intent URL
              const twitterUrl = `https://twitter.com/intent/tweet?text=${encodedText}&url=${encodedUrl}`;
              window.open(twitterUrl, '_blank');
            }
          }}
        />
      )}

      {/* Leaderboard - positioned on the right side of the chart */}
      <div style={{
        position: 'absolute',
        right: 0,
        top: 0,
        width: !isMobile ? '30%' : '35%', // Increased from 30% to 31% to make it wider to accommodate badges
        height: '100%',
        padding: '10px 0',
        display: 'flex',
        flexDirection: 'column',
        zIndex: 4
      }}>
        {/* Leaderboard players */}
        <div 
          ref={leaderboardContainerRef}
          style={{
            display: 'flex',
            flexDirection: 'column',
            gap: '0px',
            padding: '0 10px',
            marginTop: '14px', // Add some top margin since we removed the header
            overflow: 'auto',
            height: 'calc(100% - 60px)', // Adjusted since we removed some elements
            scrollbarWidth: 'none', // Firefox
            msOverflowStyle: 'none', // IE/Edge
            position: 'relative', // Added for absolute positioning of gradient overlays
            maskImage: isLeaderboardScrolled 
              ? 'linear-gradient(to bottom, transparent 0%, black 15%, black 75%, transparent 100%)' 
              : 'linear-gradient(to bottom, black 75%, transparent 100%)',
            WebkitMaskImage: isLeaderboardScrolled 
              ? 'linear-gradient(to bottom, transparent 0%, black 15%, black 75%, transparent 100%)' 
              : 'linear-gradient(to bottom, black 75%, transparent 100%)',
          }}
        >
          {/* Hide scrollbar for Chrome/Safari/Opera */}
          <style>
            {`
              div::-webkit-scrollbar {
                display: none;
              }
            `}
          </style>
          
          {/* Remove the gradient overlay div - using mask instead */}
          
          {/* Wrap entire leaderboard rendering in try-catch block to prevent crashes */}
          {(() => {
            try {
              // Create placeholder leaderboard entries if needed
              let displayLeaderboard = [...leaderboard];
              
              // Add placeholder users if needed to reach 30 total
              if (displayLeaderboard.length < 0) {
                const placeholdersNeeded = 30 - displayLeaderboard.length;
                const placeholders = Array.from({ length: placeholdersNeeded }, (_, i) => ({
                  id: `placeholder-${i}`,
                  username: `Player ${displayLeaderboard.length + i + 1}`,
                  pnl: Math.random() * 20 - 10, // Random value between -10 and 10
                  pnlPercent: Math.random() * 200 - 100, // Random percent between -100% and 100%
                  positionQty: Math.random() * 10,
                  avgCost: Math.random() * 2,
                  selectedCoin: { ticker: 'SOL', address: 'So11111111111111111111111111111111111111112' }
                }));
                
                displayLeaderboard = [...displayLeaderboard, ...placeholders];
              }
              
              // Find the current player's entry
              const currentPlayerIndex = displayLeaderboard.findIndex(entry => entry.id === playerId);
              let currentPlayerEntry = null;
              
              // If player exists in leaderboard, remove them to add at top later
              if (currentPlayerIndex !== -1) {
                currentPlayerEntry = displayLeaderboard.splice(currentPlayerIndex, 1)[0];
              }
              
              // Sort the remaining entries
              const sortedLeaderboard = displayLeaderboard
                .sort((a, b) => {
                  // Safety check - if any player has invalid/missing data, sort to bottom 
                  if (!a || !b) return 0;

                  // First check if either player has a practice token
                  const aHasPracticeToken = a.selectedCoin && a.selectedCoin.address === "0xPractice";
                  const bHasPracticeToken = b.selectedCoin && b.selectedCoin.address === "0xPractice";
                  
                  // If one has practice token and other doesn't, sort practice token to the bottom
                  if (aHasPracticeToken && !bHasPracticeToken) return 1;
                  if (!aHasPracticeToken && bHasPracticeToken) return -1;
                  
                  if (allowPreRoundBuys) {
                    // Pre-round: sort by position quantity directly instead of calculated buy-in
                    const aQty = a.positionQty || 0;
                    const bQty = b.positionQty || 0;
                    
                    // Primary sort by position quantity, highest first
                    if (Math.abs(bQty - aQty) > 0.000001) {
                      return bQty - aQty;
                    }
                    
                    // If quantities are virtually identical, use ID for stable sort
                    return (a.id || '').localeCompare(b.id || '');
                  } else {
                    // During round: sort by PNL as before
                    // Safety check for PNL values
                    const aPnl = typeof a.pnl === 'number' ? a.pnl : -Infinity;
                    const bPnl = typeof b.pnl === 'number' ? b.pnl : -Infinity;
                    
                    // Primary sort by PNL
                    if (bPnl !== aPnl) {
                      return bPnl - aPnl;
                    }
                    
                    // Secondary sort by percentage gain when PNL is equal
                    const aPnlPercent = typeof a.pnlPercent === 'number' ? a.pnlPercent : -Infinity;
                    const bPnlPercent = typeof b.pnlPercent === 'number' ? b.pnlPercent : -Infinity;
                    return bPnlPercent - aPnlPercent;
                  }
                });
              
              // Add current player at the top if they exist in leaderboard
              const finalLeaderboard = currentPlayerEntry 
                ? [currentPlayerEntry, ...sortedLeaderboard] 
                : sortedLeaderboard;
              
              return finalLeaderboard.map((player, index) => {
                // Debug player IDs to help identify issues
                const isCurrentPlayer = player.id === playerId;
                
                // IMPORTANT: Filter out invalid/incomplete entries that might be causing stuck leaderboard issues
                // Skip rendering players with invalid data based on game mode
                if (!player || 
                    // In regular game mode, skip entries with missing PNL data
                    (!allowPreRoundBuys && (player.pnl === undefined || player.pnlPercent === undefined)) || 
                    // In preround mode, skip entries with no position data (except current player)
                    (allowPreRoundBuys && !isCurrentPlayer && (!player.positionQty || player.positionQty <= 0 || !player.avgCost))
                   ) {
                  // Skip rendering this entry
                  return null;
                }
                
                return (
                  <div 
                    key={player.id} 
                    style={{
                      display: 'flex',
                      justifyContent: 'space-between',
                      alignItems: 'center',
                      padding: '0px 0px', // Added horizontal padding (was 5px 8)
                      paddingRight: '8px',
                      // Add highlight background for current player
                      backgroundColor: isCurrentPlayer ? 'rgba(255, 215, 0, 0.1)' : 'transparent',
                      // Add border for current player
                      border: isCurrentPlayer ? '1px solid rgba(255, 215, 0, 0.3)' : 'none',
                      borderRadius: isCurrentPlayer ? '4px' : '0',
                      maxWidth: '100%', // Ensure it can expand
                    }}
                  >
                    {/* Username with level badge */}
                    <div style={{
                      display: 'flex',
                      alignItems: 'center',
                      width: allowPreRoundBuys ? '60%' : (isMobile ? '70%' : '50%'), // Wider to accommodate level badge
                      overflow: 'hidden',
                      marginRight: '4px', // Add space between columns (increased from 0px)
                      paddingLeft: '0px' // Add slight padding on the left
                    }}>
                      {/* Show level badge - removed the !true condition */}
                      <div style={{ 
                        marginRight: '0px', 
                        marginLeft: '0px',
                        width: '32px',  // Further increase width to give more space
                        height: '32px', // Further increase height to give more space
                        flexShrink: 0,  // Prevent shrinking
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        overflow: 'visible' // Allow the badge to overflow the container if needed
                      }}>
                        <LevelBadge 
                          level={player.level || 1} // Default to level 1 if not provided
                          size="xs" // Use the 'xs' size which is properly defined in LevelBadge
                          showProgress={false}
                          iconOnly={true} // Only show the icon without level number to save space
                        />
                      </div>
                      <span style={{
                        color: player.id === playerId ? '#D4AF37' : '#FFF',
                        fontWeight: player.id === playerId ? 'bold' : 'normal',
                        whiteSpace: 'nowrap',
                        overflow: 'hidden',
                        textOverflow: 'ellipsis',
                        fontSize: isMobile ? '10px' : '12px', // Reduced further on mobile
                        // Add text shadow for player's own entry to make it stand out more
                        textShadow: player.id === playerId ? '0 0 5px rgba(212, 175, 55, 0.5)' : 'none'
                      }}>
                        {player.id === playerId ? 'YOU' : player.username}
                      </span>
                    </div>

                    {/* Conditional rendering based on game phase */}
                    {allowPreRoundBuys ? (
                      // Pre-round phase - show just the buy-in amount with yellow text
                      <div style={{
                        display: 'flex',
                        alignItems: 'center',
                        width: '50%',
                        justifyContent: 'flex-end',
                      }}>
                        <span style={{
                          color: '#D4AF37', // Gold text for pre-round
                          fontSize: isMobile ? '12px' : '14px', // Smaller font on mobile
                          fontWeight: player.positionQty > 0 ? 'bold' : 'normal',
                          marginRight: '2px', // Add spacing between amount and icon
                          marginBottom: '2px',
                        }}>
                          {/* Format the buy-in amount with the concise format for both mobile and desktop */}
                          {player.positionQty > 0 ? (
                            // Round to 3 decimal places to prevent floating point precision issues
                            (() => {
                              try {
                                const value = Math.round(Math.abs(player.positionQty * player.avgCost) * 1000) / 1000;
                                if (value < 1) {
                                  return '.' + value.toFixed(3).split('.')[1];
                                } else if (value < 10) {
                                  return value.toFixed(2);
                                } else if (value < 100) {
                                  return value.toFixed(1);
                                } else {
                                  return Math.floor(value);
                                }
                              } catch (e) {
                                console.error("Error formatting buy-in amount:", e);
                                return '0.00';
                              }
                            })()
                          ) : '0.00'}
                        </span>
                        <div style={{
                          width: '20px',       // Fixed width
                          height: '20px',      // Fixed height
                          marginRight: '0px',  // No margin on the right since it's now last
                          flexShrink: 0,       // Prevent shrinking
                          position: 'relative',  // For absolute positioning the image
                          borderRadius: '50%',  // Force circular shape
                          overflow: 'hidden'    // Clip content
                        }}>
                          <div style={{
                            position: 'absolute', // Position absolutely
                            top: 0,
                            left: 0,
                            width: '100%',
                            height: '100%',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                          }}>
                            <TokenIcon 
                              token={player.selectedCoin && player.selectedCoin.address === "0xPractice" 
                                ? { ticker: 'FREE', address: "0xPractice" } 
                                : (player.selectedCoin || { ticker: 'SOL', address: 'So11111111111111111111111111111111111111112' })}
                              size="sm"
                              style={{ 
                                borderRadius: '50%',  // Apply border-radius to the actual img
                                width: '100%',
                                height: '100%',
                                objectFit: 'cover'
                              }}
                            />
                          </div>
                        </div>
                      </div>
                    ) : (
                      // Regular round - show PNL and conditionally percent
                      <>
                        {/* PNL with SOL icon */}
                        <div style={{
                          display: 'flex',
                          alignItems: 'center',
                          width: isMobile ? '40%' : '30%',
                          justifyContent: 'flex-end',
                          marginRight: isMobile ? 0 : '12px', // Space between columns on desktop
                          opacity: player.positionQty > 0 ? 0.5 : 1,
                        }}>
                          <span style={{
                            color: player.pnl >= 0 ? '#00C137' : '#FF4D4D',
                            fontSize: '12px',
                            marginRight: '2px', // Add spacing between amount and icon
                          }}>
                            {/* Format the PNL value more concisely as requested */}
                            {player.pnl >= 0 ? '+' : '-'}
                            {/* Apply concise format to both mobile and desktop */}
                            {(() => {
                              try {
                                const value = Math.round(Math.abs(player.pnl) * 1000) / 1000;
                                if (value < 1) {
                                  return '.' + value.toFixed(3).split('.')[1];
                                } else if (value < 10) {
                                  return value.toFixed(2);
                                } else if (value < 100) {
                                  return value.toFixed(1);
                                } else {
                                  return Math.floor(value);
                                }
                              } catch (e) {
                                console.error("Error formatting PNL value:", e);
                                return '0.00';
                              }
                            })()}
                          </span>
                          <div style={{
                            width: '20px',       // Fixed width
                            height: '20px',      // Fixed height
                            marginRight: '0px',  // No margin on the right since it's now last
                            flexShrink: 0,       // Prevent shrinking
                            position: 'relative', // For absolute positioning the image
                            borderRadius: '50%',  // Force circular shape
                            overflow: 'hidden'    // Clip content
                          }}>
                            <div style={{
                              position: 'absolute', // Position absolutely
                              top: 0,
                              left: 0,
                              width: '100%',
                              height: '100%',
                              display: 'flex',
                              alignItems: 'center',
                              justifyContent: 'center'
                            }}>
                              <TokenIcon 
                                token={player.selectedCoin && player.selectedCoin.address === "0xPractice" 
                                  ? { ticker: 'FREE', address: "0xPractice" } 
                                  : (player.selectedCoin || { ticker: 'SOL', address: 'So11111111111111111111111111111111111111112' })}
                                size="sm"
                                style={{ 
                                  borderRadius: '50%',  // Apply border-radius to the actual img
                                  width: '100%',
                                  height: '100%',
                                  objectFit: 'cover'
                                }}
                              />
                            </div>
                          </div>
                        </div>

                        {/* Percent PNL - hide on mobile */}
                        {!isMobile && (
                          <div style={{
                            width: '30%',
                            textAlign: 'right',
                            color: player.pnlPercent >= 0 ? '#00C137' : '#FF4D4D',
                            opacity: player.positionQty > 0 ? 0.5 : 1,
                            fontSize: '14px',
                          }}>
                            {player.pnlPercent >= 0 ? '+' : '-'}{Math.abs(player?.pnlPercent).toFixed(2)}%
                          </div>
                        )}
                      </>
                    )}
                  </div>
                );
              });
            } catch (error) {
              // Log the error to help diagnose the issue
              console.error("Error rendering leaderboard:", error);
              
              // Return a simple fallback UI when leaderboard rendering fails
              return (
                <div style={{ 
                  padding: '10px', 
                  color: 'white',
                  textAlign: 'center'
                }}>
                  <div style={{ marginBottom: '10px' }}>Leaderboard unavailable</div>
                  <div style={{ fontSize: '12px', color: '#aaa' }}>
                    {leaderboard.length} players in round
                  </div>
                </div>
              );
            }
          })()}
        </div>
      </div>
      
      {/* Add Golden Hour indicator at the top of the chart */}
      <div style={{ 
        position: 'absolute', 
        top: '10px', 
        left: '50%', 
        transform: 'translateX(-50%)',
        zIndex: 8
      }}>
        <GoldenHourIndicator isMobile={isMobile} />
      </div>
      
      {/* Golden Hour drawing overlay */}
      <GoldenHourDrawing 
        drawingData={goldenHourDrawing}
        visible={showGoldenHourDrawing}
        onComplete={handleCloseGoldenHourDrawing}
      />
      
      {/* Add RugpoolOverlay */}
      <RugpoolOverlay
        isVisible={showRugpoolOverlay}
        overlayType={rugpoolOverlayType}
        rugpoolData={rugpoolOverlayData}
        position={isMobile ? "top" : "center"}
        onClose={() => {
          console.log("Closing rugpool overlay");
          setShowRugpoolOverlay(false);
        }}
      />
    </div>
  );
}

export default RugChart; 