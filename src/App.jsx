import React, { useState, useEffect, useRef } from "react";
import "./App.css";
import createBlockies from "ethereum-blockies-base64";

// Mock data for chart and players
const initialMockChartData = [];  // Changed to empty array to prevent candles showing on load

// Mock token data for trade markers
const mockTokens = [
  { symbol: "SOL", logo: "/solanaLogoMark (1).svg" },
  { symbol: "FREE", logo: "/rugslogo.svg" },
];

// Mock trade markers data - we'll add to this dynamically during simulation
const initialMockTrades = [];

// Mock ETH addresses for generating blockies
const mockAddresses = [
  "0x1234567890123456789012345678901234567890",
  "0x2345678901234567890123456789012345678901",
  "0x3456789012345678901234567890123456789012",
  "0x4567890123456789012345678901234567890123",
  "0x5678901234567890123456789012345678901234",
  "0x6789012345678901234567890123456789012345",
  "0x7890123456789012345678901234567890123456",
  "0x8901234567890123456789012345678901234567",
  "0x9012345678901234567890123456789012345678",
  "0x0123456789012345678901234567890123456789"
];

const mockPlayers = [
  { name: "anon", address: mockAddresses[0], profit: "+0.827", percent: "+20.66%" },
  { name: "corksuccer", address: mockAddresses[1], profit: "+0.827", percent: "+20.66%" },
  { name: "anon", address: mockAddresses[2], profit: "+0.827", percent: "+20.66%" },
  { name: "kub", address: mockAddresses[3], profit: "+0.827", percent: "+20.66%" },
  { name: "Krisi18", address: mockAddresses[4], profit: "+0.827", percent: "+20.66%" },
  { name: "Gorillarug", address: mockAddresses[5], profit: "+0.827", percent: "+20.66%" },
  { name: "Gob", address: mockAddresses[6], profit: "+0.827", percent: "+20.66%" },
  { name: "Oreoz", address: mockAddresses[7], profit: "+0.827", percent: "+20.66%" },
  { name: "Romms", address: mockAddresses[8], profit: "+0.827", percent: "+20.66%" },
  { name: "Gob", address: mockAddresses[9], profit: "+0.827", percent: "+20.66%" },
];

const CHART_HEIGHT = 400;
const CHART_WIDTH = 800;
const CANDLE_WIDTH = 26;
const CANDLE_GAP = 4;
const MIN_VALUE = 0.3; // Minimum fixed value
const ABSOLUTE_MAX_VALUE = 50.0; // Theoretical maximum for calculations
const LEADERBOARD_WIDTH = 420;

// Brighter, more contrasted grid line colors (top to bottom)
const gridLineColors = [
  "#FF3B3B", // bright red (top)
  "#FF6EC7", // bright pink
  "#FFF700", // bright yellow
  "#FFFFFF", // white (center)
  "#FFF700", // bright yellow
  "#3B6EFF", // bright blue
  "#1A1AFF"  // deep blue (bottom)
];

// Dynamic grid line generation based on the current visible range
function generateGridLines(maxValue) {
  // Determine a good step size based on the max value
  let stepSize;
  if (maxValue <= 2) stepSize = 0.2;
  else if (maxValue <= 5) stepSize = 0.5;
  else if (maxValue <= 10) stepSize = 1;
  else if (maxValue <= 20) stepSize = 2;
  else stepSize = 5;
  
  // Generate grid lines from 0.4x up to maxValue
  const multipliers = [];
  let current = 0.4; // Always start at 0.4x
  
  while (current <= maxValue) {
    multipliers.push(current);
    current += stepSize;
  }
  
  // Ensure we have at least 7 lines and at most 10
  if (multipliers.length < 7) {
    // Add finer lines if we have too few
    stepSize /= 2;
    multipliers.length = 0;
    current = 0.4;
    while (current <= maxValue) {
      multipliers.push(current);
      current += stepSize;
    }
  } else if (multipliers.length > 10) {
    // Take just enough evenly spaced lines
    const step = Math.ceil(multipliers.length / 10);
    const filteredMultipliers = [];
    for (let i = 0; i < multipliers.length; i += step) {
      filteredMultipliers.push(multipliers[i]);
    }
    return filteredMultipliers;
  }
  
  return multipliers;
}

function Logo() {
  return (
    <div className="logo-container">
      <h1 className="logo-text">RUGS.FUN</h1>
      <span className="beta-tag">BETA</span>
    </div>
  );
}

function TestControls({ onStartTest, isRunning, onStopTest }) {
  return (
    <div className="test-controls">
      {!isRunning ? (
        <button className="test-button" onClick={onStartTest}>
          Start Test Game
        </button>
      ) : (
        <button className="test-button stop" onClick={onStopTest}>
          Stop Test
        </button>
      )}
      <div className="test-info">
        Simulates a trading game with random price movements
      </div>
    </div>
  );
}

function PlayerBox({ player, index }) {
  const profit = player.profit.replace("+", "▲");
  const blockieImage = createBlockies(player.address); // Generate blockie from ETH address
  
  return (
    <div className="player-row leaderboard-gradient-border">
      <div className="avatar-container">
        <img 
          src={blockieImage} 
          alt="Player Avatar" 
          className="player-avatar-blockie"
        />
      </div>
      <span className="player-name">{player.name}</span>
      <div className="player-stats">
        <span className="player-profit green-glow">{profit}</span>
        <span className="player-percent green-glow">{player.percent}</span>
      </div>
    </div>
  );
}

// Trade marker component that will be used to render trade notifications
function TradeMarker({ trade, x, y, opacity = 1 }) {
  // Fixed dimensions for the notification box
  const boxWidth = 120;
  const boxHeight = 50;

  // Color based on trade type (buy = green, sell = red)
  const color = trade.type === 'buy' ? '#00A63E' : '#E7000B';

  // Layout constants
  const logoSize = 16;
  const logoY = y - boxHeight / 2 + 6; // 6px from top
  const usernameY = logoY + logoSize + 8; // 8px gap below logo
  const tradeTextY = usernameY + 16; // 16px gap below username

  return (
    <g className="trade-marker" opacity={opacity}>
      {/* Semi-transparent black background box */}
      <rect
        x={x - boxWidth/2}
        y={y - boxHeight/2}
        width={boxWidth}
        height={boxHeight}
        rx={4}
        ry={4}
        fill="rgba(0, 0, 0, 0.7)"
        stroke="none"
      />
      
      {/* Corner decorations - L-shaped corners only */}
      {/* Top-left corner */}
      <line x1={x - boxWidth/2} y1={y - boxHeight/2 + 8} x2={x - boxWidth/2} y2={y - boxHeight/2} stroke={color} strokeWidth={2} />
      <line x1={x - boxWidth/2} y1={y - boxHeight/2} x2={x - boxWidth/2 + 8} y2={y - boxHeight/2} stroke={color} strokeWidth={2} />
      
      {/* Top-right corner */}
      <line x1={x + boxWidth/2 - 8} y1={y - boxHeight/2} x2={x + boxWidth/2} y2={y - boxHeight/2} stroke={color} strokeWidth={2} />
      <line x1={x + boxWidth/2} y1={y - boxHeight/2} x2={x + boxWidth/2} y2={y - boxHeight/2 + 8} stroke={color} strokeWidth={2} />
      
      {/* Bottom-left corner */}
      <line x1={x - boxWidth/2} y1={y + boxHeight/2 - 8} x2={x - boxWidth/2} y2={y + boxHeight/2} stroke={color} strokeWidth={2} />
      <line x1={x - boxWidth/2} y1={y + boxHeight/2} x2={x - boxWidth/2 + 8} y2={y + boxHeight/2} stroke={color} strokeWidth={2} />
      
      {/* Bottom-right corner */}
      <line x1={x + boxWidth/2 - 8} y1={y + boxHeight/2} x2={x + boxWidth/2} y2={y + boxHeight/2} stroke={color} strokeWidth={2} />
      <line x1={x + boxWidth/2} y1={y + boxHeight/2} x2={x + boxWidth/2} y2={y + boxHeight/2 - 8} stroke={color} strokeWidth={2} />
      
      {/* Token logo centered above the username */}
      <svg x={x - logoSize/2} y={logoY} width={logoSize} height={logoSize} viewBox="0 0 20 20">
        <defs>
          <clipPath id={`token-clip-${trade.id}`}>
            <circle cx="10" cy="10" r="10" />
          </clipPath>
        </defs>
        <image
          href={trade.token.logo}
          width="20"
          height="20"
          clipPath={`url(#token-clip-${trade.id})`}
        />
      </svg>
      
      {/* Username in monospace font */}
      <text
        x={x}
        y={usernameY}
        fontSize="12"
        fontFamily="monospace"
        fill="#FFFFFF"
        textAnchor="middle"
        dominantBaseline="middle"
      >
        {trade.username.length >= 9 ? trade.username.substring(0, 8) + '...' : trade.username}
      </text>
      
      {/* Buy/Sell text with amount in colored text */}
      <text
        x={x}
        y={tradeTextY}
        fontSize="12"
        fontFamily="monospace"
        fill={color}
        textAnchor="middle"
        dominantBaseline="middle"
        fontWeight="bold"
      >
        {trade.type.toUpperCase()} {trade.amount.toFixed(1)} {trade.token.symbol}
      </text>
    </g>
  );
}

function GameGraph() {
  const [chartData, setChartData] = useState(initialMockChartData);
  const [currentMultiplier, setCurrentMultiplier] = useState(1.0);
  const [isTestRunning, setIsTestRunning] = useState(false);
  const [visibleMaxValue, setVisibleMaxValue] = useState(2.0);
  const [displayMultiplier, setDisplayMultiplier] = useState(1.0);
  const [currentTick, setCurrentTick] = useState(0);
  const [windowWidth, setWindowWidth] = useState(window.innerWidth);
  
  // Change from single activeTrade to array of active trades
  const [activeTradesMap, setActiveTradesMap] = useState({});
  const [tradeOpacity, setTradeOpacity] = useState(1);
  
  // Add a ref to track the absolute candle index from the start of simulation
  const absoluteCandleIndexRef = useRef(0);
  
  const testIntervalRef = useRef(null);
  const animationFrameRef = useRef(null);
  const previousMultiplierRef = useRef(currentMultiplier);
  const lastTradeTimeRef = useRef(0);
  const fadeTimeoutRef = useRef(null);
  
  // Add window resize listener
  useEffect(() => {
    const handleResize = () => {
      setWindowWidth(window.innerWidth);
    };
    
    window.addEventListener('resize', handleResize);
    return () => {
      window.removeEventListener('resize', handleResize);
    };
  }, []);
  
  // Generate grid lines based on the current visible range
  const gridLineMultipliers = generateGridLines(visibleMaxValue);
  
  // Normalize function for calculating y positions based on visible range
  const norm = v => {
    return CHART_HEIGHT - (((v - MIN_VALUE) / (visibleMaxValue - MIN_VALUE)) * (CHART_HEIGHT - 40) + 20);
  };
  
  // Get the last (most recent) candle's value
  // Always default to 1.0 if no candles are available to match production
  const lastCandleValue = chartData.length > 0 ? chartData[chartData.length - 1].close : 1.0;
  
  // Function to generate a new price point with smoother but still dramatic movements
  const generateNextPrice = (currentPrice) => {
    // Base movement - more dramatic now
    const randomFactor = Math.random() * 0.15 - 0.075; // Between -0.075 and 0.075
    
    // For smoother transitions with more frequent updates, scale the jumps appropriately
    const bigJumpChance = 0.4; // Reduced chance of big jumps from 60% to 40%
    let trendValue = 0;
    
    if (Math.random() < bigJumpChance) {
      // Big movement - more controlled to prevent extreme outliers
      const direction = Math.random() < 0.5 ? 1 : -1; // Equal chance up or down
      const magnitude = Math.random() * 0.2 + 0.1; // 10% to 30% jump (reduced from 15% to 45%)
      trendValue = direction * magnitude;
    } else {
      // Normal movement (still quite large)
      trendValue = Math.random() < 0.5 ? 0.08 : -0.06; // More balanced movements
    }
    
    // Calculate new price with more controlled movement
    let newPrice = currentPrice * (1 + randomFactor + trendValue);
    
    // More aggressive bounds checking
    // Keep values in a more reasonable range for visualization
    const maxAllowedMultiplier = Math.max(visibleMaxValue * 0.8, 20.0); // Cap at current view * 0.8 or 20x
    newPrice = Math.max(MIN_VALUE + 0.1, Math.min(maxAllowedMultiplier, newPrice));
    
    return parseFloat(newPrice.toFixed(4));
  };
  
  // Function to generate a new trade marker
  const generateTrade = (candleIndex, price) => {
    // Randomly select a player and token
    const playerIndex = Math.floor(Math.random() * mockPlayers.length);
    const player = mockPlayers[playerIndex];
    const tokenIndex = Math.floor(Math.random() * mockTokens.length);
    const token = mockTokens[tokenIndex];
    
    // Decide if it's a buy or sell
    const type = Math.random() > 0.5 ? 'buy' : 'sell';
    
    // Generate random amount (between 0.1 and 2.0)
    const amount = 0.1 + Math.random() * 1.9;
    
    // Create a unique ID using timestamp and random number
    const uniqueId = `${Date.now()}-${Math.floor(Math.random() * 1000)}`;
    
    return {
      id: uniqueId,
      candleIndex,             // Store relative candle index in current chart data
      absoluteCandleIndex: absoluteCandleIndexRef.current,  // Store absolute candle index
      price,                   // Store the actual price (for vertical positioning)
      username: player.name,
      address: player.address,
      token,
      type,
      amount,
      timestamp: Date.now(),
      opacity: 1,              // Start fully visible
    };
  };
  
  // Function to add a new trade
  const addNewTrade = (trade) => {
    setActiveTradesMap(prev => ({
      ...prev,
      [trade.id]: trade
    }));
    
    // Start fade out after 2.5 seconds
    setTimeout(() => {
      const fadeOutInterval = setInterval(() => {
        setActiveTradesMap(prev => {
          const currentTrade = prev[trade.id];
          if (!currentTrade) {
            clearInterval(fadeOutInterval);
            return prev;
          }
          
          // Reduce opacity
          const newOpacity = currentTrade.opacity - 0.03;
          
          if (newOpacity <= 0) {
            // Remove the trade when fully faded
            const newMap = {...prev};
            delete newMap[trade.id];
            clearInterval(fadeOutInterval);
            return newMap;
          }
          
          // Update opacity
          return {
            ...prev,
            [trade.id]: {
              ...currentTrade,
              opacity: newOpacity
            }
          };
        });
      }, 50);
    }, 2500);
  };
  
  // Modified startTestSimulation to use the new multi-trade approach
  const startTestSimulation = () => {
    if (testIntervalRef.current) return;
    setIsTestRunning(true);
    
    // Reset chart data with initial state - start at 1.0x
    const initialCandle = { high: 1.0, low: 1.0, open: 1.0, close: 1.0, timestamp: Date.now() };
    setChartData([initialCandle]);
    setActiveTradesMap({}); // Clear any existing trades
    setCurrentMultiplier(1.0);
    setVisibleMaxValue(2.0);
    setCurrentTick(0);
    
    // Reset absolute candle index counter
    absoluteCandleIndexRef.current = 0;
    
    // Set up the tick interval (250ms per tick)
    testIntervalRef.current = setInterval(() => {
      setCurrentTick(prevTick => {
        const newTick = prevTick + 1;
        
        setChartData(prevData => {
          // Ensure we have data to work with
          if (prevData.length === 0) {
            const newCandle = { high: 1.0, low: 1.0, open: 1.0, close: 1.0, timestamp: Date.now() };
            return [newCandle];
          }
          
          const lastCandle = prevData[prevData.length - 1];
          const newPrice = generateNextPrice(lastCandle.close);
          
          // Generate trades randomly (approximately 1 per second)
          const currentTime = Date.now();
          if (currentTime - lastTradeTimeRef.current >= 1000) { // 1 second interval
            if (Math.random() < 0.8) { // 80% chance to generate a trade
              const candleIndex = prevData.length - 1;
              const tradePrice = newPrice;
              const newTrade = generateTrade(candleIndex, tradePrice);
              
              // Add the new trade
              addNewTrade(newTrade);
              
              // Update last trade time
              lastTradeTimeRef.current = currentTime;
            }
          }
          
          // Every 5 ticks (1.25 seconds), create a new candle
          if (newTick % 5 === 0) {
            // Increment the absolute candle index counter
            absoluteCandleIndexRef.current++;
            
            // Create a new candle with HLOC values
            const newCandle = {
              high: Math.max(lastCandle.close, newPrice),
              low: Math.min(lastCandle.close, newPrice),
              open: lastCandle.close,
              close: newPrice,
              timestamp: Date.now(),
              absoluteIndex: absoluteCandleIndexRef.current  // Store absolute index with candle
            };
            
            const newData = [...prevData, newCandle];
            // Keep only the last 20 candles
            if (newData.length > 20) {
              return newData.slice(-20);
            }
            return newData;
          } else {
            // Update the current candle's HLOC values
            const updatedCandle = {
              ...lastCandle,
              high: Math.max(lastCandle.high, newPrice),
              low: Math.min(lastCandle.low, newPrice),
              close: newPrice
            };
            
            const newData = [...prevData];
            newData[newData.length - 1] = updatedCandle;
            return newData;
          }
        });
        
        // Update the multiplier separately to ensure it's always in sync
        setChartData(currentData => {
          if (currentData.length === 0) return currentData;
          const lastCandle = currentData[currentData.length - 1];
          setCurrentMultiplier(lastCandle.close);
          return currentData;
        });
        
        return newTick;
      });
    }, 250); // 250ms per tick
  };
  
  // Modified stopTestSimulation to clean up multi-trade resources
  const stopTestSimulation = () => {
    if (testIntervalRef.current) {
      clearInterval(testIntervalRef.current);
      testIntervalRef.current = null;
      setIsTestRunning(false);
      
      // Clear all active trades
      setActiveTradesMap({});
    }
  };
  
  // Clean up interval on unmount
  useEffect(() => {
    return () => {
      if (testIntervalRef.current) {
        clearInterval(testIntervalRef.current);
      }
      if (animationFrameRef.current) {
        cancelAnimationFrame(animationFrameRef.current);
      }
      if (fadeTimeoutRef.current) {
        clearTimeout(fadeTimeoutRef.current);
      }
    };
  }, []);
  
  // Update the visible range when the multiplier changes significantly
  useEffect(() => {
    // Store the previous multiplier for animation reference
    previousMultiplierRef.current = currentMultiplier;
    
    // Get the highest value in current chart data to ensure everything fits
    const highestValueInChartData = chartData.reduce((max, candle) => 
      Math.max(max, candle.high), currentMultiplier);
    
    // Always ensure the current multiplier and highest candle are within the visible range
    // Add 20% headroom above the highest value for extreme price jumps
    const idealMaxValue = Math.max(highestValueInChartData * 1.2, 2.0);
    
    // Make range adjustments more gradual for smoother transitions
    // But ensure quick adaptation when price moves outside visible range
    if (highestValueInChartData >= visibleMaxValue || Math.abs(idealMaxValue - visibleMaxValue) / visibleMaxValue > 0.15) {
      // Move quickly to accommodate values outside visible range
      const newMaxValue = highestValueInChartData >= visibleMaxValue 
        ? idealMaxValue 
        : visibleMaxValue + (idealMaxValue - visibleMaxValue) * 0.3;
      
      setVisibleMaxValue(newMaxValue);
    }
  }, [currentMultiplier, visibleMaxValue, chartData]);

  // Animate multiplier value
  useEffect(() => {
    if (displayMultiplier === currentMultiplier) return;
    const animateValue = () => {
      // Calculate step size based on difference
      const diff = currentMultiplier - displayMultiplier;
      // Much faster animation for near-instant effect
      const step = diff * 0.5;
      
      if (Math.abs(diff) < 0.0001) {
        setDisplayMultiplier(currentMultiplier);
        cancelAnimationFrame(animationFrameRef.current);
        return;
      }
      
      const newValue = displayMultiplier + step;
      setDisplayMultiplier(newValue);
      
      animationFrameRef.current = requestAnimationFrame(animateValue);
    };
    animationFrameRef.current = requestAnimationFrame(animateValue);
    return () => {
      if (animationFrameRef.current) {
        cancelAnimationFrame(animationFrameRef.current);
      }
    };
  }, [currentMultiplier, displayMultiplier]);
  
  return (
    <div className="app-container">
      <Logo />
      <TestControls 
        onStartTest={startTestSimulation} 
        isRunning={isTestRunning} 
        onStopTest={stopTestSimulation} 
      />
      <div className="game-graph-container">
        <div className="game-content">
          {/* Unified container for both chart and leaderboard */}
          <div className="unified-container">
            {/* Background grid lines that span the unified container but stop at leaderboard edge */}
            <div className="grid-background">
              <svg width="100%" height="100%">
                {/* Remove the horizontal grid lines from here since they're now in the main chart SVG */}
              </svg>
            </div>
            
            {/* Chart area with candles */}
            <div className="chart-container" style={{ overflow: 'visible' }}>
              <div className="chart-area" style={{ overflow: 'visible' }}>
                {/* Move the grid lines and labels inside this SVG to ensure proper layering */}
                <svg width="100%" height="100%" preserveAspectRatio="xMidYMid meet" style={{ overflow: 'visible' }}>
                  <defs>
                    <filter id="glow" x="-50%" y="-50%" width="200%" height="200%">
                      <feGaussianBlur stdDeviation="2.5" result="coloredBlur"/>
                      <feMerge>
                        <feMergeNode in="coloredBlur"/>
                        <feMergeNode in="SourceGraphic"/>
                      </feMerge>
                    </filter>
                    <linearGradient id="red-gradient" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="0%" stopColor="#F01100" />
                      <stop offset="53%" stopColor="#FF4D9D" />
                      <stop offset="100%" stopColor="#FFDC40" />
                    </linearGradient>
                    <linearGradient id="blue-gradient" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="0%" stopColor="#FFFFFF" />
                      <stop offset="38%" stopColor="#263BF0" />
                      <stop offset="100%" stopColor="#131313" />
                    </linearGradient>
                    
                    {/* Gradients for trade notification borders */}
                    <linearGradient id="buy-gradient" x1="0%" y1="0%" x2="0%" y2="100%">
                      <stop offset="0%" stopColor="#00A63E" stopOpacity="0" />
                      <stop offset="70%" stopColor="#00A63E" stopOpacity="1" />
                      <stop offset="100%" stopColor="#00A63E" stopOpacity="1" />
                    </linearGradient>
                    <linearGradient id="sell-gradient" x1="0%" y1="0%" x2="0%" y2="100%">
                      <stop offset="0%" stopColor="#E7000B" stopOpacity="0" />
                      <stop offset="70%" stopColor="#E7000B" stopOpacity="1" />
                      <stop offset="100%" stopColor="#E7000B" stopOpacity="1" />
                    </linearGradient>
                    
                    <linearGradient id="multiplier-line-gradient" x1="0" y1="0" x2="1" y2="0">
                      <stop offset="0%" stopColor="#000000" />
                      <stop offset="50%" stopColor="#263BF0" />
                      <stop offset="100%" stopColor="#F01100" />
                    </linearGradient>
                    <linearGradient id="leaderboard-fade-top" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="0%" stopColor="#000" stopOpacity="1" />
                      <stop offset="15%" stopColor="#000" stopOpacity="0" />
                      <stop offset="100%" stopColor="#000" stopOpacity="0" />
                    </linearGradient>
                    <linearGradient id="leaderboard-fade-bottom" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="0%" stopColor="#000" stopOpacity="0" />
                      <stop offset="85%" stopColor="#000" stopOpacity="0" />
                      <stop offset="100%" stopColor="#000" stopOpacity="1" />
                    </linearGradient>
                    {/* Rainbow gradient for price indicator */}
                    <linearGradient id="rainbow-gradient" x1="0%" y1="0%" x2="100%" y2="0%" gradientUnits="userSpaceOnUse">
                      <stop offset="0%" stopColor="#F01100">
                        <animate attributeName="offset" values="0;0.25;0.5;0.75;1;0" dur="8s" repeatCount="indefinite" />
                      </stop>
                      <stop offset="25%" stopColor="#FF4D9D">
                        <animate attributeName="offset" values="0.25;0.5;0.75;1;0.25;0.25" dur="8s" repeatCount="indefinite" />
                      </stop>
                      <stop offset="50%" stopColor="#FFDC40">
                        <animate attributeName="offset" values="0.5;0.75;1;0.25;0.5;0.5" dur="8s" repeatCount="indefinite" />
                      </stop>
                      <stop offset="75%" stopColor="#263BF0">
                        <animate attributeName="offset" values="0.75;1;0.25;0.5;0.75;0.75" dur="8s" repeatCount="indefinite" />
                      </stop>
                      <stop offset="100%" stopColor="#F01100">
                        <animate attributeName="offset" values="1;0.25;0.5;0.75;1;1" dur="8s" repeatCount="indefinite" />
                      </stop>
                    </linearGradient>
                  </defs>
                  
                  {/* First draw the grid lines */}
                  {gridLineMultipliers.map((y, i) => (
                    <g key={`grid-${i}`}>
                      <line
                        x1={0}
                        x2="120%" /* Extend beyond container width to flow behind leaderboard */
                        y1={norm(y)}
                        y2={norm(y)}
                        stroke={gridLineColors[i % gridLineColors.length]}
                        strokeWidth={0.5}
                        opacity={1}
                      />
                    </g>
                  ))}
                  
                  {/* Then draw the candles */}
                  {chartData.length > 0 && isTestRunning && chartData.map((candle, i) => {
                    const prev = i > 0 ? chartData[i - 1] : candle;
                    const up = candle.close >= candle.open;
                    const gradientId = up ? "red-gradient" : "blue-gradient";
                    
                    // Calculate responsive values based on window width
                    const isMobile = windowWidth <= 640;
                    const isSmall = windowWidth <= 900 && windowWidth > 640;
                    
                    // Get container dimensions
                    const chartContainer = document.querySelector('.chart-container');
                    const containerWidth = chartContainer ? chartContainer.clientWidth : CHART_WIDTH;
                    
                    // Reserve space for leaderboard based on container size
                    // Be extremely aggressive with the right margin to ensure candles are never cut off
                    const leaderboardWidth = isMobile ? 160 : (isSmall ? 240 : 320);
                    const rightMargin = containerWidth * 0.25 + leaderboardWidth * 0.1;
                    
                    // Calculate candle dimensions
                    const maxVisibleCandles = 15;
                    const candleWidthRatio = windowWidth <= 640 ? 0.6 : 1;
                    const responsiveCandleWidth = Math.max(
                      4, 
                      Math.min(CANDLE_WIDTH * candleWidthRatio, ((containerWidth - rightMargin) / maxVisibleCandles) * 0.8)
                    );
                    
                    // Calculate gap between candles
                    const gap = Math.max(1, Math.min(CANDLE_GAP, containerWidth / 320));
                    
                    // Position the latest candle with a fixed offset from the right edge
                    const isLatestCandle = i === chartData.length - 1;
                    const distanceFromEnd = chartData.length - 1 - i;
                    
                    // Calculate the available width for chart
                    const availableWidth = containerWidth - rightMargin;
                    
                    // Calculate candle positions from right to left
                    // Latest candle is positioned with safe margin from the right edge
                    const SAFE_MARGIN = containerWidth * 0.09;  // Further reduced margin to position candle closer to leaderboard
                    const latestCandleX = availableWidth - SAFE_MARGIN;
                    const x = latestCandleX - (distanceFromEnd * (responsiveCandleWidth + gap));
                    
                    // Calculate candle body dimensions
                    const bodyTop = norm(Math.max(candle.open, candle.close));
                    const bodyBottom = norm(Math.min(candle.open, candle.close));
                    const bodyHeight = Math.max(bodyBottom - bodyTop, 2); // Ensure minimum height
                    
                    // Calculate wick dimensions
                    const wickTop = norm(candle.high);
                    const wickBottom = norm(candle.low);
                    
                    // Only render candles that are in the visible area
                    // Allow candles to run off left side but ensure visible on right
                    if (x > containerWidth) return null;
                    
                    return (
                      <g key={`candle-${i}`}>
                        {/* Wick */}
                        <line
                          x1={x + responsiveCandleWidth / 2}
                          y1={wickTop}
                          x2={x + responsiveCandleWidth / 2}
                          y2={wickBottom}
                          stroke={up ? "#FF3B3B" : "#3B6EFF"}
                          strokeWidth={isMobile ? 1 : 2}
                        />
                        
                        {/* Candle body */}
                        <rect
                          x={x}
                          y={bodyTop}
                          width={responsiveCandleWidth}
                          height={bodyHeight}
                          fill={`url(#${gradientId})`}
                          style={{
                            filter: up ? "drop-shadow(0 0 4px #FF3B3B) drop-shadow(0 0 8px #FF6EC7)" : "none"
                          }}
                        />
                      </g>
                    );
                  })}
                  
                  {/* Draw the indicator lines on top of candles */}
                  {chartData.length > 0 && isTestRunning && (
                    <g>
                      <line
                        x1="0"
                        x2="120%" /* Extend beyond container width to flow behind leaderboard */
                        y1={norm(displayMultiplier)}
                        y2={norm(displayMultiplier)}
                        stroke="url(#rainbow-gradient)"
                        strokeWidth={2}
                        className="current-price-indicator"
                        style={{ 
                          filter: "drop-shadow(0 0 4px rgba(255, 255, 255, 0.8))"
                        }}
                      />
                      {/* Add the multiplier text directly in the SVG */}
                      <text
                        x="97%" // Move further right, away from candles
                        y={norm(displayMultiplier) - 15}
                        fill="#FFFFFF"
                        fontSize={windowWidth <= 640 ? 18 : windowWidth <= 900 ? 22 : 28}
                        textAnchor="end"
                        dominantBaseline="middle"
                        style={{
                          filter: "drop-shadow(0 0 10px rgba(0,0,0,0.8)) drop-shadow(0 0 20px rgba(0,0,0,0.8))",
                          fontFamily: "Inter, Arial, sans-serif",
                          fontWeight: 700
                        }}
                      >
                        {displayMultiplier.toFixed(4)}X
                      </text>
                    </g>
                  )}
                  
                  {/* Multiplier gradient line - always at 1.0x as reference */}
                  {isTestRunning && (
                    <line
                      x1="0"
                      x2="120%" /* Extend beyond container width to flow behind leaderboard */
                      y1={norm(1.0)} /* Always keep the multiplier line at 1.0x */
                      y2={norm(1.0)}
                      stroke="url(#multiplier-line-gradient)"
                      strokeWidth={1.5}
                      strokeDasharray="none"
                    />
                  )}
                  
                  {/* Draw all active trade markers AFTER indicator lines, using a higher z-index group */}
                  <g style={{ isolation: "isolate" }}>
                    {isTestRunning && Object.values(activeTradesMap).map(trade => {
                      // Calculate how many candles have passed since this trade
                      // Use absolute candle index to maintain proper positioning
                      const candleAge = absoluteCandleIndexRef.current - trade.absoluteCandleIndex;
                      
                      // Only render trades for visible candles (considering our 20 candle limit)
                      if (candleAge >= 20) return null;
                      
                      // Calculate the current relative position in the visible chart
                      const currentRelativePosition = chartData.length - 1 - candleAge;
                      
                      // If the position is negative, the candle is off-screen to the left
                      if (currentRelativePosition < 0) return null;
                      
                      // Calculate responsive values based on window width
                      const isMobile = windowWidth <= 640;
                      const isSmall = windowWidth <= 900 && windowWidth > 640;
                      
                      const chartContainer = document.querySelector('.chart-container');
                      const containerWidth = chartContainer ? chartContainer.clientWidth : CHART_WIDTH;
                      
                      const leaderboardWidth = isMobile ? 160 : (isSmall ? 240 : 320);
                      const rightMargin = containerWidth * 0.25 + leaderboardWidth * 0.1;
                      
                      const candleWidthRatio = windowWidth <= 640 ? 0.6 : 1;
                      const responsiveCandleWidth = Math.max(
                        4, 
                        Math.min(CANDLE_WIDTH * candleWidthRatio, ((containerWidth - rightMargin) / 15) * 0.8)
                      );
                      
                      const gap = Math.max(1, Math.min(CANDLE_GAP, containerWidth / 320));
                      
                      const availableWidth = containerWidth - rightMargin;
                      const SAFE_MARGIN = containerWidth * 0.09;
                      const latestCandleX = availableWidth - SAFE_MARGIN;
                      
                      // Calculate distance from latest candle using our absolute position calculation
                      const distanceFromEnd = chartData.length - 1 - currentRelativePosition;
                      
                      // Calculate x position based on candle position
                      const x = latestCandleX - (distanceFromEnd * (responsiveCandleWidth + gap)) + responsiveCandleWidth / 2;
                      
                      // Calculate y position based on the original price
                      // This ensures vertical position stays at the same price level
                      const y = norm(trade.price);
                      
                      return (
                        <TradeMarker
                          key={trade.id}
                          trade={trade}
                          x={x}
                          y={y}
                          opacity={trade.opacity}
                        />
                      );
                    })}
                  </g>
                  
                  {/* Finally draw the grid labels on top */}
                  {gridLineMultipliers.map((y, i) => (
                    <g key={`grid-label-${i}`}>
                      <text
                        x={10}
                        y={norm(y) - 8}
                        fill="#FFFFFF"
                        fontSize={14}
                        fontWeight="600"
                        style={{ 
                          textShadow: "0 0 8px #000, 1px 1px 0 #000, -1px -1px 0 #000, 1px -1px 0 #000, -1px 1px 0 #000",
                          filter: "drop-shadow(0 0 2px rgba(0,0,0,0.9))"
                        }}
                        className="grid-line-label"
                      >
                        {y.toFixed(1)}x
                      </text>
                    </g>
                  ))}
                </svg>
              </div>
            </div>
            
            {/* Player list with fade effects */}
            <div className="player-list-container" style={{ position: 'relative', background: 'none', zIndex: 10 }}>
              <div className="player-list-fade top"></div>
              <div className="player-list">
                {mockPlayers.map((player, idx) => (
                  <PlayerBox key={idx} player={player} index={idx} />
                ))}
              </div>
              <div className="player-list-fade bottom"></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

function CandleChart({ data, currentMultiplier, gridLineMultipliers, norm }) {
  return (
    <svg width={CHART_WIDTH} height={CHART_HEIGHT} className="chart-svg">
      <defs>
        <linearGradient id="red-gradient" x1="0%" y1="0%" x2="0%" y2="100%">
          <stop offset="0%" stopColor="#FF3B3B" />
          <stop offset="100%" stopColor="#FF6EC7" />
        </linearGradient>
        <linearGradient id="blue-gradient" x1="0%" y1="0%" x2="0%" y2="100%">
          <stop offset="0%" stopColor="#3B6EFF" />
          <stop offset="100%" stopColor="#1A1AFF" />
        </linearGradient>
      </defs>
      
      {/* Grid lines */}
      {gridLineMultipliers.map((multiplier, i) => (
        <g key={`grid-${i}`}>
          <line
            x1="0"
            y1={norm(multiplier)}
            x2={CHART_WIDTH}
            y2={norm(multiplier)}
            stroke={gridLineColors[i % gridLineColors.length]}
            strokeWidth="1"
            strokeDasharray="5,5"
          />
          <text
            x="5"
            y={norm(multiplier) - 5}
            fill={gridLineColors[i % gridLineColors.length]}
            fontSize="12"
          >
            {multiplier.toFixed(1)}x
          </text>
        </g>
      ))}
      
      {/* Candles */}
      {data.map((candle, i) => {
        const x = i * (CANDLE_WIDTH + CANDLE_GAP);
        const up = candle.close >= candle.open;
        const gradientId = up ? "red-gradient" : "blue-gradient";
        
        // Calculate candle body dimensions
        const bodyTop = norm(Math.max(candle.open, candle.close));
        const bodyBottom = norm(Math.min(candle.open, candle.close));
        const bodyHeight = bodyBottom - bodyTop;
        
        // Calculate wick dimensions
        const wickTop = norm(candle.high);
        const wickBottom = norm(candle.low);
        
        return (
          <g key={`candle-${i}`}>
            {/* Wick */}
            <line
              x1={x + CANDLE_WIDTH / 2}
              y1={wickTop}
              x2={x + CANDLE_WIDTH / 2}
              y2={wickBottom}
              stroke={up ? "#FF3B3B" : "#3B6EFF"}
              strokeWidth="2"
            />
            
            {/* Candle body */}
            <rect
              x={x}
              y={bodyTop}
              width={CANDLE_WIDTH}
              height={bodyHeight}
              fill={`url(#${gradientId})`}
              stroke={up ? "#FF3B3B" : "#3B6EFF"}
              strokeWidth="1"
            />
          </g>
        );
      })}
    </svg>
  );
}

export default function App() {
  return <GameGraph />;
}
